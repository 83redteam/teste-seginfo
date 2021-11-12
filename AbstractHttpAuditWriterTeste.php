
<?php

namespace \HttpAudit\Writer;

use \HttpAudit\HttpAuditMessage;
use \HttpAudit\NotCommittedMessageException;

abstract class AbstractHttpAuditWriter implements HttpAuditWriterInterface
{
    
    private $scheduledMessages = [];

   
    private $buffered;

    /**
     * @var bool
     */
    private $initialized = false;

    /**
     * @param bool $buffered Buffer the messages, then flush them on shutting down
     */
    public function __construct(bool $buffered = true)
    {
        // Prevent memory leak on CLI
        $this->buffered = $buffered && php_sapi_name() !== 'cli';
    }

    /**
     * @param string $path
     * @param string $content
     *
     * @return bool
     */
    abstract protected function writeContent(string $path, string $content): bool;

    /**
     * @param string $path
     * @param HttpAuditMessage $message
     *
     * @return string
     * @throws \Exception On format error
     */
    abstract protected function formatContent(string $path, HttpAuditMessage $message): string;

    public function isBuffered(): bool
    {
        return $this->buffered;
    }

    /**
     * @inheritDoc
     */
    public function writeMessage($subPath, HttpAuditMessage $message): string
    {
        $path = $this->getFullPath($subPath, $message->getDatetime());

        // initialization because GC
        if ($this->buffered && !$this->initialized) {
            $this->registerShutdownFlush();

            $this->initialized = true;
        }

        $this->scheduledMessages[$path] = $message;

        if (!$this->buffered) {
            $this->flush();
        }

        return $path;
    }

    /**
     * @param string|null $subPath
     */
    protected function getFullPath($subPath, \DateTimeInterface $datetime): string
    {
        // sanitize subpath
        if (!empty($subPath)) {
            $subPath = trim(preg_replace('#/{2,}#', '/', $subPath), '/');
        }

        return $datetime->format('Y') . '/' . $datetime->format('m') . '/' . $datetime->format('d')
            . (!empty($subPath) ? '/' . $subPath : '')
            . '/' . $this->getAuditFilename();
    }

    public function getAuditFilename(): string
    {
        return 'audit.json';
    }

    /**
     * Persist the queued messages.
     */
    public function flush()
    {
        /** @var HttpAuditMessage $scheduledMessage */
        foreach ($this->scheduledMessages as $path => $scheduledMessage) {
            try {
                $content = $this->formatContent($path, $scheduledMessage);

                if (!$this->writeContent($path, $content)) {
                    throw new \RuntimeException('Error while writing the message.');
                }
            } catch (\Throwable $e) {
                // Prevent memory leak
                $this->scheduledMessages = [];

                // Buffered is implicitly quiet
                if (!$this->buffered) {
                    throw new NotCommittedMessageException($scheduledMessage, $e);
                }
            }
        }

        $this->scheduledMessages = [];
    }

    private function registerShutdownFlush()
    {
        register_shutdown_function([$this, 'flush']);
    }
}

