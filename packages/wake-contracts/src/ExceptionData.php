<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeContracts;

use ArtisanBuild\WakeContracts\Contracts\ArrayContract;
use ArtisanBuild\WakeContracts\Contracts\SerializesContract;
use ArtisanBuild\WakeContracts\Exceptions\ContractValidationException;
use ArtisanBuild\WakeContracts\Support\ContractInput;

final readonly class ExceptionData implements ArrayContract
{
    use SerializesContract;

    public const int MAX_CLASS_BYTES = 255;

    public const int MAX_MESSAGE_BYTES = 65_535;

    public const int MAX_FILE_BYTES = 4_096;

    public const int MAX_TRACE_BYTES = 65_535;

    public function __construct(
        public string $class,
        public string $message,
        public string $file,
        public int $line,
        public string $trace,
    ) {
        ContractInput::boundedString($class, '$.exception.class', self::MAX_CLASS_BYTES);
        ContractInput::boundedString($message, '$.exception.message', self::MAX_MESSAGE_BYTES, allowEmpty: true);
        ContractInput::boundedString($file, '$.exception.file', self::MAX_FILE_BYTES);
        ContractInput::boundedString($trace, '$.exception.trace', self::MAX_TRACE_BYTES, allowEmpty: true);

        if ($line < 1) {
            throw ContractValidationException::at(
                '$.exception.line',
                'must be a positive integer',
            );
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input, string $path = '$.exception'): self
    {
        ContractInput::assertShape($input, ['class', 'message', 'file', 'line', 'trace'], path: $path);

        return new self(
            class: ContractInput::string($input, 'class', $path),
            message: ContractInput::string($input, 'message', $path),
            file: ContractInput::string($input, 'file', $path),
            line: ContractInput::integer($input, 'line', $path),
            trace: ContractInput::string($input, 'trace', $path),
        );
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray(ContractInput::decodeObject($json), '$');
    }

    /** @return array{class: string, message: string, file: string, line: int, trace: string} */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'trace' => $this->trace,
        ];
    }
}
