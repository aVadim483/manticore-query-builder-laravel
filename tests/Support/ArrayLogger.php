<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger which just collects records in memory
 */
class ArrayLogger extends AbstractLogger
{
    /**
     * @var array
     */
    public $records = [];

    /**
     * @param mixed $level
     * @param mixed $message
     * @param array $context
     *
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => $level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }

    /**
     * @param string $level
     *
     * @return array
     */
    public function recordsOfLevel(string $level): array
    {
        return array_values(array_filter($this->records, static function ($record) use ($level) {
            return $record['level'] === $level;
        }));
    }

    /**
     * @return void
     */
    public function reset(): void
    {
        $this->records = [];
    }
}
