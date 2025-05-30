<?php
/**
 * @license MIT
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WPO\WC\PDF_Invoices_Pro\Vendor\Spatie\Dropbox\Exceptions;

use Exception;
use WPO\WC\PDF_Invoices_Pro\Vendor\Psr\Http\Message\ResponseInterface;

class BadRequest extends Exception
{
    /**
     * @var \WPO\WC\PDF_Invoices_Pro\Vendor\Psr\Http\Message\ResponseInterface
     */
    public $response;

    /**
     * The dropbox error code supplied in the response.
     *
     * @var string|null
     */
    public $dropboxCode;

    public function __construct(ResponseInterface $response)
    {
        $this->response = $response;

        $body = json_decode($response->getBody(), true);

        if ($body !== null) {
            if (isset($body['error']['.tag'])) {
                $this->dropboxCode = $body['error']['.tag'];
            }

            parent::__construct($body['error_summary']);
        }
    }
}
