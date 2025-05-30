<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 10-August-2021 using Strauss.
 * @see https://github.com/BrianHenryIE/strauss
 */

namespace WPO\WC\PDF_Invoices_Pro\Vendor\Illuminate\Contracts\Support;

interface Jsonable
{
    /**
     * Convert the object to its JSON representation.
     *
     * @param  int  $options
     * @return string
     */
    public function toJson($options = 0);
}
