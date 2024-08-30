<?php

namespace Leaf\UI;

use Leaf\UI;

/**
 * Leaf UI Core
 * ---
 * Core functions for Leaf UI
 */
class Core
{
    /** Scripts to embed into view */
    public static $scripts = [];

    /** State to embed into view */
    public static $state = [];

    /** List of components in a view */
    public static $components = [];

    /** List of component methods */
    public static $componentMethods = [];

    /** List of mapped component methods */
    public static $mappedComponentMethods = [];

    /**
     * Process a Leaf UI component and return the HTML
     * 
     * @param Component $name The name of the component
     * @param array $config Configuration for the component
     * @param bool $withoutScripts Whether to return the HTML without scripts
     * @param callable $callback The callback to run when the component is rendered
     */
    public static function buildComponent(Component $component, array $config, bool $withoutScripts = false)
    {
        // if userState from get async
        if (is_string($config['type'] ?? null)) {
            // called for async requests and compiling of components
            return static::buildComponentAsync($component, $config, $withoutScripts);
        }
        
        // set static::$state
        static::$state[$component->key] = array_merge(
            // init classState
            get_class_vars($component::class),
            ['key' => $component->key],
            // merge databaseState
            // merge userState, will be []
            static::$state[$component->key] ?? []
        );

        // set $pageState
        $pageState = [];
        foreach (array_values(static::$state) as $key => $value) {
            $pageState = array_merge($pageState, $value);
        }

        // set $componentState
        $componentState = static::$state[$component->key];
        // insert ui-state attribute to html element
        $parsedComponent = static::insertStateAttribute($component, $componentState);

        return [
            'responseType' => 'html',
            'html' => $withoutScripts ?
                $parsedComponent :
                str_replace('</body>', UI::createElement('script', [], ['
                    window._leafUIConfig = {
                        el: document.querySelector("body"),
                        component: "' . $component::class . '",
                        components: ' . json_encode(static::$components) . ',
                        data: ' . json_encode(array_merge($pageState, ['key' => $component->key])) . ',
                        methods: ' . self::jsonEncodeToSimpleArray(array_unique(static::$componentMethods)) . ',
                        path: "' . $_SERVER['REQUEST_URI'] . '",
                        requestMethod: "' . $_SERVER['REQUEST_METHOD'] . '",
                    };
                ']) . UI::init() . '</body>', $parsedComponent),
        ];
    }

    /**
     * Process a Leaf UI component asynchronously
     * 
     * @param Component $component The component to process
     */
    public static function buildComponentAsync($component, $config, $withoutScripts = false)
    {
        $componentCalled = $component;

        if (is_string($config['payload']['data'])) {
            $config['payload']['data'] = json_decode($config['payload']['data'], true);
        }

        if (isset($config['payload']['component']) && isset(static::$components[$config['payload']['component']])) {
            $componentCalled = new (static::$components[$config['payload']['component']]);
            $componentCalled->key = $config['payload']['component'];

            foreach ($config['payload']['data'][$config['payload']['component']] as $key => $value) {
                if (property_exists($componentCalled, $key)) {
                    $componentCalled->{$key} = $value;
                    static::$state[$componentCalled->key][$key] = $value;
                }
            }
        } else if (isset($component->key) && isset(static::$components[$component->key])) {
            $componentCalled = new (static::$components[$component->key]);
            $componentCalled->key = $component->key;

            if (isset($config['payload']['data'][$componentCalled->key])) {
                foreach ($config['payload']['data'][$componentCalled->key] as $key => $value) {
                    if (property_exists($componentCalled, $key)) {
                        $componentCalled->{$key} = $value;
                        static::$state[$componentCalled->key][$key] = $value;
                    }
                }
            } else {
                foreach ($config['payload']['data'] as $key => $value) {
                    if (property_exists($componentCalled, $key)) {
                        $componentCalled->{$key} = $value;
                        static::$state[$componentCalled->key][$key] = $value;
                    }
                }
            }
        }

        if ($config['type'] === 'callMethod') {
            return static::callComponentMethod($componentCalled, $config, $withoutScripts);
        }

        foreach ($config['payload']['data'] as $key => $value) {
            if (property_exists($componentCalled, $key)) {
                static::$state[$componentCalled->key][$key] = $componentCalled->{$key};
            }
        }

        $componentState = static::$state[$componentCalled->key];

        // insert ui-state attribute to html element
        $parsedComponent = static::insertStateAttribute($component, $componentState);

        //    return [
        $response = [
            'responseType' => 'json',
            'html' => $withoutScripts ?
                $parsedComponent :
                str_replace(
                    '</body>',
                    UI::createElement('script', [], ['
                            window._leafUIConfig.methods = ' . self::jsonEncodeToSimpleArray(array_unique(static::$componentMethods)) . ';
                            window._leafUIConfig.components = ' . json_encode(static::$components) . ';
                        ']) . UI::init() . '</body>',
                    $parsedComponent
                ),
        ];

        return $response;
    }

    /**
     * Call a component method
     * 
     * @param Component $component The component to call the method on
     * @param array $config The configuration for the method
     */
    public static function callComponentMethod($component, $config, $withoutScripts = true)
    {
        // compile and render to get the latest state (we don't really output anything)
        Parser::compileTemplate($component->render(), static::mergeState());

        $config['payload']['methodArgs'] = explode(',', $config['payload']['methodArgs']);
        $methodToCall = [$component, $config['payload']['method']];

        if (!method_exists($component, $config['payload']['method'])) {
            foreach (static::$mappedComponentMethods as $wKey => $wValue) {
                if (in_array($config['payload']['method'], $wValue)) {
                    $component = new (static::$components[$wKey]);
                    $methodToCall = [$component, $config['payload']['method']];

                    // set the state for the component
                    foreach ($config['payload']['data'][$config['payload']['component']] as $key => $value) {
                        if (property_exists($component, $key)) {
                            $component->{$key} = $value;
                        }
                    }
                    break;
                }
            }
        }

        call_user_func(
            $methodToCall,
            ...$config['payload']['methodArgs']
        );

        foreach ($config['payload']['data'][$config['payload']['component']] as $key => $value) {
            if (property_exists($component, $key)) {
                static::$state[$config['payload']['component']][$key] = $component->{$key};
            }
        }

        // set component key to latest component key overwriting new component key 
        $component->key = $config['payload']['component'];

        $parsedComponent = static::insertStateAttribute($component, static::$state[$config['payload']['component']]);

        return [
            'responseType' => 'json',
            'html' => $withoutScripts ?
                $parsedComponent :
                str_replace(
                    '</body>',
                    UI::createElement('script', [], ['
                                window._leafUIConfig.methods = ' . self::jsonEncodeToSimpleArray(array_unique(static::$componentMethods)) . ';
                                window._leafUIConfig.components = ' . json_encode(static::$components) . ';
                            ']) . UI::init() . '</body>',
                    $parsedComponent
                ),
        ];
    }

    public static function mergeState(array $extraState = []): array
    {
        $state = [];

        foreach (array_values(static::$state) as $key => $value) {
            $state = array_merge($state, $value);
        }

        return array_merge($state, $extraState);
    }

    private static function insertStateAttribute($component, $componentState): string
    {
        // insert ui-state attribute to html element
        return preg_replace(
            '/<(\w+)([^>]*)>/i',
            "<$1 ui-state='" . json_encode($componentState) . "' $2>",
            $component->render($componentState),
            1
        );
    }

    private static function jsonEncodeToSimpleArray($arr): string {
        $simple = array();
        foreach($arr as $key => $value){
            $simple[] = $value;
        }
        $json = json_encode(($simple));
        return $json;
    }
}
