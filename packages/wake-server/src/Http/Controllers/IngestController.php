<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Http\Controllers;

use ArtisanBuild\BuiltForCloud\AppPurposeRegistry;
use ArtisanBuild\BuiltForCloud\Auth\BearerAuthenticator;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialOwnership;
use ArtisanBuild\BuiltForCloud\CredentialUsageRecorder;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\WakeContracts\Enums\ErrorCode;
use ArtisanBuild\WakeContracts\ErrorResponse;
use ArtisanBuild\WakeContracts\Exceptions\ContractValidationException;
use ArtisanBuild\WakeContracts\IngestEnvelope;
use ArtisanBuild\WakeContracts\IngestProtocol;
use ArtisanBuild\WakeServer\Ingest\IngestDecision;
use ArtisanBuild\WakeServer\Ingest\IngestService;
use ArtisanBuild\WakeServer\Models\ConnectedApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IngestController
{
    public function __construct(
        private readonly BearerAuthenticator $authenticator,
        private readonly AppPurposeRegistry $purposes,
        private readonly CredentialUsageRecorder $usage,
        private readonly IngestService $ingest,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $credential = $this->authenticator->credential($request);

        if ($credential === null) {
            return $this->error(401, ErrorCode::Unauthorized, 'A valid bearer credential is required.');
        }

        if (! $this->mayIngest($credential)) {
            return $this->error(403, ErrorCode::Forbidden, 'Credential is not allowed to ingest.');
        }

        $appId = $credential->subject_ref;
        /** @var ConnectedApp|null $app */
        $app = ConnectedApp::query()->find($appId);

        if ($app === null || ! $app->enabled) {
            return $this->error(403, ErrorCode::Forbidden, 'Credential is not allowed to ingest.');
        }

        if (! $this->usage->recordUsage($credential)) {
            return $this->error(401, ErrorCode::Unauthorized, 'A valid bearer credential is required.');
        }

        $body = $request->getContent();
        if (strlen($body) > IngestProtocol::MAX_REQUEST_BYTES) {
            $this->ingest->recordRejection($appId);

            return $this->error(413, ErrorCode::PayloadTooLarge, 'Request body exceeds 1048576 bytes.');
        }

        $contentTypeHeader = $request->header(IngestProtocol::CONTENT_TYPE_HEADER);
        $contentType = is_string($contentTypeHeader)
            ? strtolower(trim(explode(';', $contentTypeHeader, 2)[0]))
            : '';
        if ($contentType !== IngestProtocol::CONTENT_TYPE) {
            $this->ingest->recordRejection($appId);

            return $this->validation('Content-Type', 'must be application/json');
        }

        try {
            $envelope = IngestEnvelope::fromJson($body);
            $idempotencyHeader = $request->header(IngestProtocol::IDEMPOTENCY_HEADER);
            IngestProtocol::assertIdempotencyHeader(is_string($idempotencyHeader) ? $idempotencyHeader : '', $envelope);
        } catch (ContractValidationException $exception) {
            $this->ingest->recordRejection($appId);

            if ($exception->path === 'envelope_version') {
                return $this->error(
                    422,
                    ErrorCode::UnsupportedEnvelopeVersion,
                    $exception->getMessage(),
                    ['path' => $exception->path],
                );
            }

            return $this->validation($exception->path, $exception->getMessage());
        }

        $duplicate = $this->ingest->duplicate($appId, $envelope->idempotencyKey);

        return $this->respond($duplicate ?? $this->ingest->accept($appId, $envelope));
    }

    private function mayIngest(Credential $credential): bool
    {
        return $credential->purpose === $this->purposes->purpose('wake.ingest')
            && $credential->ownership() === CredentialOwnership::Installation
            && $credential->subject_type === SubjectType::Installation;
    }

    /** @param array<string, mixed> $details */
    private function error(int $status, ErrorCode $code, string $message, array $details = []): JsonResponse
    {
        return response()->json((new ErrorResponse($status, $code, $message, $details))->toArray(), $status);
    }

    private function validation(string $path, string $message): JsonResponse
    {
        return $this->error(
            IngestProtocol::UNPROCESSABLE_STATUS,
            ErrorCode::ValidationFailed,
            $message,
            ['path' => $path],
        );
    }

    private function respond(IngestDecision $decision): JsonResponse
    {
        return response()->json($decision->body, $decision->status, $decision->headers);
    }
}
