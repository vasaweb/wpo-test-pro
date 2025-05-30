<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 10-August-2021 using Strauss.
 * @see https://github.com/BrianHenryIE/strauss
 */

namespace WPO\WC\PDF_Invoices_Pro\Vendor\Illuminate\Support;

/**
 * @mixin \WPO\WC\PDF_Invoices_Pro\Vendor\Illuminate\Support\Collection
 */
class HigherOrderCollectionProxy
{
    /**
     * The collection being operated on.
     *
     * @var \WPO\WC\PDF_Invoices_Pro\Vendor\Illuminate\Support\Collection
     */
    protected $collection;

    /**
     * The method being proxied.
     *
     * @var string
     */
    protected $method;

    /**
     * Create a new proxy instance.
     *
     * @param  \WPO\WC\PDF_Invoices_Pro\Vendor\Illuminate\Support\Collection  $collection
     * @param  string  $method
     * @return void
     */
    public function __construct(Collection $collection, $method)
    {
        $this->method = $method;
        $this->collection = $collection;
    }

    /**
     * Proxy accessing an attribute onto the collection items.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->collection->{$this->method}(function ($value) use ($key) {
            return is_array($value) ? $value[$key] : $value->{$key};
        });
    }

    /**
     * Proxy a method call onto the collection items.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->collection->{$this->method}(function ($value) use ($method, $parameters) {
            return $value->{$method}(...$parameters);
        });
    }
}
