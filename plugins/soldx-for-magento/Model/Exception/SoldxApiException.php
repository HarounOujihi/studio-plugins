<?php
/**
 * Soldx API exception — carries the HTTP status code from Studio responses.
 */
declare(strict_types=1);

namespace Soldx\Integration\Model\Exception;

use Magento\Framework\Exception\LocalizedException;

class SoldxApiException extends LocalizedException
{
    /**
     * HTTP status code from the Studio API (0 for network errors).
     *
     * @var int
     */
    private int $statusCode;

    /**
     * @param string $message
     * @param int $statusCode
     * @param \Exception|null $cause
     */
    public function __construct(string $message, int $statusCode = 0, ?\Exception $cause = null)
    {
        $this->statusCode = $statusCode;
        parent::__construct(__($message), $cause);
    }

    /**
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
