<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2017 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Garden\Web;


/**
 * The base class for routes.
 *
 * A route maps {@link RequestInterface} instances to callbacks. A single route will analyze the request and return
 * dispatch information or **null** if it can't map the route.
 */
abstract class Route {
    const MAP_ARGS = 0x1; // map to path args
    const MAP_QUERY = 0x2; // map to querystring
    const MAP_BODY = 0x4; // map to post body

    /**
     * Route constructor.
     */
    public function __construct() {
        $this
            ->setConstraint('id', ['regex' => '`^\d+$`', 'position' => 0])
            ->setConstraint('page', '`^p\d+$`');
    }

    /**
     * @var array An array of parameter conditions.
     */
    private $constraints;

    /**
     * @var array An array of parameter mappings.
     */
    private $mappings = [
        'query' => Route::MAP_QUERY,
        'body' => Route::MAP_BODY,
        'data' => Route::MAP_ARGS | Route::MAP_QUERY | Route::MAP_BODY
    ];

    /**
     * Get the conditions.
     *
     * @return array Returns the conditions.
     */
    public function getConstraints() {
        return $this->constraints;
    }

    /**
     * Set the entire conditions array.
     *
     * If you are going to set conditions in this way make sure the array keys are all lowercase.
     *
     * @param array $constraints An array of conditions that map variable names to **Route::MAP_*** constants.
     * @return $this
     */
    public function setConstraints(array $constraints) {
        $this->constraints = $constraints;
        return $this;
    }

    /**
     * Test whether a parameter name has a condition attached to it.
     *
     * @param \ReflectionParameter $parameter The parameter name to test.
     * @return bool Returns **true** if the parameter has a condition or **false** otherwise.
     */
    public function hasConstraint(\ReflectionParameter $parameter) {
        if (!isset($this->constraints[strtolower($parameter->getName())])) {
            return false;
        }
        $constraint = $this->constraints[strtolower($parameter->getName())];
        if (isset($constraint['position']) && $constraint['position'] !== $parameter->getPosition()) {
            return false;
        }
        return true;
    }

    /**
     * Set a condition for a parameter name.
     *
     * @param string $name The parameter to attach the condition to.
     * @param callable|string|array $condition Either a callback or a regular expression that can be passed to {@link preg_match()}.
     */
    public function setConstraint($name, $condition) {
        if (is_callable($condition)) {
            $constraint = ['callback' => $condition];
        } elseif (is_string($condition)) {
            $constraint = ['regex' => $condition];
        } else {
            $constraint = $condition;
        }

        $this->constraints[strtolower($name)] = $constraint;
        return $this;
    }

    /**
     * Get the condition for a parameter.
     *
     * @param string $name The parameter name to get the condition for.
     * @return callable|string|null Returns the condition or **null** if there is no condition.
     */
    public function getConstraint($name) {
        $name = strtolower($name);

        if (isset($this->constraints[$name])) {
            return $this->constraints[$name];
        } else {
            return null;
        }
    }

    /**
     * Get the mappings.
     *
     * @return array Returns the mappings.
     */
    public function getMappings() {
        return $this->mappings;
    }

    /**
     * Set the entire mappings array.
     *
     * If you are going to set the mappings array in this way then it should have lowercase keys.
     *
     * @param array $mappings The new mappings array.
     * @return $this
     */
    public function setMappings($mappings) {
        $this->mappings = $mappings;
        return $this;
    }

    /**
     * Get the mapping for a parameter name.
     *
     * @param string $name The name of a parameter.
     * @return int Returns a one or a combination of the **Route::MAP_*** constants or **0** if there is no mapping.
     */
    public function getMapping($name) {
        $name = strtolower($name);
        return isset($this->mappings[$name]) ? $this->mappings[$name] : 0;
    }

    /**
     * Set the mapping for a parameter.
     *
     * @param string $name The name of the parameter.
     * @param int $value One or a combination of the **Route::MAP_*** constants.
     */
    public function setMapping($name, $value) {
        $this->mappings[strtolower($name)] = $value;
    }

    /**
     * Tests whether an argument passes a condition.
     *
     * The test will pass if the condition is met or there is no condition with the given name.
     *
     * @param string $parameter The name of the parameter.
     * @param string $value The value of the argument.
     * @param array $meta Additional meta information to test. If an array is specified then the constraint properties
     * are checked to see if they match the meta values.
     * @return bool Returns **true** if the condition passes or there is no condition. Returns **false** otherwise.
     */
    protected function testConstraint(\ReflectionParameter $parameter, $value, array $meta = []) {
        if ($this->hasConstraint($parameter)) {
            $constraint = $this->constraints[strtolower($parameter->getName())];

            // Check the meta information.
            foreach ($meta as $metaKey => $metaValue) {
                if (isset($constraint[$metaKey]) && $constraint[$metaKey] !== $metaValue) {
                    return false;
                }
            }

            if (!empty($constraint['callback'])) {
                return $constraint['callback']($value);
            } elseif (!empty($constraint['regex'])) {
                return preg_match($constraint['regex'], $value);
            }
        }
        return true;
    }

    /**
     * Determine whether or not a parameter is mapped to special request data.
     *
     * @param \ReflectionParameter $parameter The name of the parameter to check.
     * @return bool Returns true if the parameter is mapped, false otherwise.
     */
    protected function isMapped(\ReflectionParameter $parameter) {
        return $parameter->isArray() && !empty($this->mappings[strtolower($parameter->getName())]);
    }

    /**
     * Get the mapped data for a parameter.
     *
     * @param string $name The name of the parameter.
     * @param RequestInterface $request The request to get the data from.
     * @param array $args The parsed path arguments for a method.
     * @return array|null Returns the mapped data or null if there is no data.
     */
    protected function mapParam($name, RequestInterface $request, array $args = []) {
        $name = strtolower($name);

        if (isset($this->mappings[$name])) {
            $mapping = $this->mappings[$name];
        } else {
            return null;
        }

        $result = [];

        if ($mapping & self::MAP_ARGS) {
            $result += $args;
        }
        if ($mapping & self::MAP_QUERY) {
            $result += $request->getQuery();
        }
        if ($mapping & self::MAP_BODY) {
            $result += $request->getBody();
        }

        return $result;
    }

    /**
     * Match the route to a request.
     *
     * @param RequestInterface $request The request to match against.
     * @return mixed Returns match information or **null** if the route doesn't match.
     */
    abstract public function match(RequestInterface $request);
}
