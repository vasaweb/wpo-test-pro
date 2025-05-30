<?php
/**
 * @license MIT
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\PhpseclibV3;

use WPO\WC\PDF_Invoices_Pro\Vendor\phpseclib3\Net\SFTP;

class FixatedConnectivityChecker implements ConnectivityChecker
{
    /**
     * @var int
     */
    private $succeedAfter;

    /**
     * @var int
     */
    private $numberOfTimesChecked = 0;

    public function __construct(int $succeedAfter = 0)
    {
        $this->succeedAfter = $succeedAfter;
    }

    public function isConnected(SFTP $connection): bool
    {
        if ($this->numberOfTimesChecked >= $this->succeedAfter) {
            return true;
        }

        $this->numberOfTimesChecked++;

        return false;
    }
}
