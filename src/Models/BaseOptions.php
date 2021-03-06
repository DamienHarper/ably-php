<?php
namespace Ably\Models;

use Ably\Log;

/**
 * Base class for models with options
 * Provides automatic loading of options from array or an object
 */
abstract class BaseOptions {
    public function __construct( $options = [] ) {
        $class = get_class( $this );
        
        foreach ($options as $key => $value) {
            if (property_exists( $class, $key )) {
                $this->$key = $value;
            }
        }
    }

    public function toArray() {
        $properties = call_user_func('get_object_vars', $this);
        foreach ($properties as $k => $v) {
            if ($v === null) {
                unset($properties[$k]);
            }
        }
        return $properties;
    }
}