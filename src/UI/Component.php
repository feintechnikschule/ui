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


    /**
     * Embeded components must not implement this method. 
     * Their state will be loaded by componentes rendering a full page loading state from the database for all components
     */
    public function getDbState(): array
    {
        return [];
    }

    /**
     * Only components with methods need this method to store state changes in the database
     * @param string $proName Name of the property with changing value
     * @param mixed $newValue The new value of the property
     */
    public function addDbStateChanged(string $propName, $newValue): void 
    {
        $newStateChanged = [
           "Key" =>$this->key,
           "Prop" => $propName,
           "Value" => $newValue,
           "Created" => date(DATE_ATOM)
        ];
        StateChanged::Add($newStateChanged);
    }
}
