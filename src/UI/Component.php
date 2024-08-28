<?php

namespace Leaf\UI;

abstract class Component
{
    /**
     * @var string $key The unique key of the component must be set and always the same
     */
    public string $key;

    /**
     * @abstract All components must implement this method using their view and state for rendering
     * @param $state The state of the component for rendering the view. If omitted the global state is used
     * @return string The html string of the component
     */
    abstract public function render($state = []): string;
    }
}
