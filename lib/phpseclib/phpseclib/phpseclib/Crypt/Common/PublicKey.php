<?php

/**
 * PublicKey interface
 *
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2009 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WPO\WC\PDF_Invoices_Pro\Vendor\phpseclib3\Crypt\Common;

/**
 * PublicKey interface
 *
 * @author  Jim Wigginton <terrafrost@php.net>
 */
interface PublicKey
{
    public function verify($message, $signature);
    //public function encrypt($plaintext);
    public function toString($type, array $options = []);
    public function getFingerprint($algorithm);
}
