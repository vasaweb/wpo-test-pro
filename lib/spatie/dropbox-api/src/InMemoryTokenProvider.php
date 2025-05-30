<?php
/**
 * @license MIT
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WPO\WC\PDF_Invoices_Pro\Vendor\Spatie\Dropbox;

class InMemoryTokenProvider implements TokenProvider
{
    /**
     * @var string
     */
    private $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function getToken(): string
    {
        return $this->token;
    }
}
