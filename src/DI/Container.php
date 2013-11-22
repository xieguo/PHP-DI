<?php
/**
 * PHP-DI
 *
 * @link      http://mnapoli.github.io/PHP-DI/
 * @copyright Matthieu Napoli (http://mnapoli.fr/)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT (see the LICENSE file)
 */

namespace DI;

use DI\Definition\AliasDefinition;
use DI\Definition\ClassDefinition;
use DI\Definition\CallableDefinition;
use DI\Definition\DefinitionManager;
use DI\Definition\ValueDefinition;
use DI\DefinitionHelper\DefinitionHelper;
use Exception;
use InvalidArgumentException;
use ProxyManager\Configuration as ProxyManagerConfiguration;
use ProxyManager\Factory\LazyLoadingValueHolderFactory;
use ProxyManager\GeneratorStrategy\EvaluatingGeneratorStrategy;

/**
 * Dependency Injection Container
 *
 * @author Matthieu Napoli <matthieu@mnapoli.fr>
 */
class Container
{
    /**
     * Map of instances of entry with Singleton scope
     * @var array
     */
    private $entries = array();

    /**
     * @var DefinitionManager
     */
    private $definitionManager;

    /**
     * @var Injector
     */
    private $injector;

    /**
     * @var LazyLoadingValueHolderFactory
     */
    private $proxyFactory;

    /**
     * Array of classes being instantiated.
     * Used to avoid circular dependencies.
     * @var array
     */
    private $classesBeingInstantiated = array();

    /**
     * Parameters are optional, use them to override a dependency.
     *
     * @param DefinitionManager|null             $definitionManager
     * @param Injector|null              $injector
     * @param LazyLoadingValueHolderFactory|null $proxyFactory
     */
    public function __construct(
        DefinitionManager $definitionManager = null,
        Injector $injector = null,
        LazyLoadingValueHolderFactory $proxyFactory = null
    ) {
        $this->definitionManager = $definitionManager ?: new DefinitionManager(true, true);
        $this->injector = $injector ?: new DefaultInjector($this);
        $this->proxyFactory = $proxyFactory ?: $this->createDefaultProxyFactory();

        // Auto-register the container
        $this->entries[get_class($this)] = $this;
    }

    /**
     * Returns an instance by its name
     *
     * @param string $name Entry name or a class name
     * @param bool   $useProxy If true, returns a proxy class of the instance
     *                         if it is not already loaded
     * @throws InvalidArgumentException
     * @throws DependencyException
     * @throws NotFoundException
     * @return mixed Instance
     */
    public function get($name, $useProxy = false)
    {
        if (!is_string($name)) {
            throw new InvalidArgumentException("The name parameter must be of type string");
        }

        // Try to find the entry in the map
        if (array_key_exists($name, $this->entries)) {
            return $this->entries[$name];
        }

        // Entry not loaded, use the definitions
        $definition = $this->definitionManager->getDefinition($name);

        // It's a value
        if ($definition instanceof ValueDefinition) {
            $this->entries[$name] = $definition->getValue();
            return $this->entries[$name];
        }

        // It's a closure
        if ($definition instanceof CallableDefinition) {
            $callable = $definition->getCallable();
            $this->entries[$name] = $callable($this);
            return $this->entries[$name];
        }

        // It's an alias
        if ($definition instanceof AliasDefinition) {
            return $this->get($definition->getTargetEntryName(), $useProxy);
        }

        // It's a class
        if ($definition instanceof ClassDefinition) {
            // Return a proxy class
            if ($useProxy || $definition->isLazy()) {
                $instance = $this->getProxy($definition);
            } else {
                $instance = $this->getNewInstance($definition);
            }

            if ($definition->getScope() == Scope::SINGLETON()) {
                // If it's a singleton, store the newly created instance
                $this->entries[$name] = $instance;
            }

            return $instance;
        }

        throw new NotFoundException("No entry or class found for '$name'");
    }

    /**
     * Test if the container can provide something for the given name
     *
     * @param string $name Entry name or a class name
     * @throws \InvalidArgumentException
     * @return bool
     */
    public function has($name)
    {
        if (! is_string($name)) {
            throw new InvalidArgumentException("The name parameter must be of type string");
        }

        return array_key_exists($name, $this->entries) || $this->definitionManager->getDefinition($name);
    }

    /**
     * Inject all dependencies on an existing instance
     *
     * @param object $instance Object to perform injection upon
     * @throws InvalidArgumentException
     * @throws DependencyException
     * @return object $instance Returns the same instance
     */
    public function injectOn($instance)
    {
        $definition = $this->definitionManager->getDefinition(get_class($instance));

        // Check that the definition is a class definition
        if ($definition instanceof ClassDefinition) {
            $instance = $this->injector->injectOnInstance($definition, $instance);
        }

        return $instance;
    }

    /**
     * Define an object or a value in the container
     *
     * @param string                 $name  Entry name
     * @param mixed|DefinitionHelper $value Value, use definition helpers to define objects
     */
    public function set($name, $value)
    {
        // Clear existing entry if it exists
        if (array_key_exists($name, $this->entries)) {
            unset($this->entries[$name]);
        }

        if ($value instanceof DefinitionHelper) {
            $definition = $value->getDefinition($name);
        } else {
            $definition = new ValueDefinition($name, $value);
        }

        $this->definitionManager->addDefinition($definition);
    }

    /**
     * Add definitions from an array
     *
     * @param array $definitions
     */
    public function addDefinitions(array $definitions)
    {
        $this->definitionManager->addArrayDefinitions($definitions);
    }

    /**
     * @param Injector $injector
     */
    public function setInjector(Injector $injector)
    {
        $this->injector = $injector;
    }

    /**
     * @return Injector
     */
    public function getInjector()
    {
        return $this->injector;
    }

    /**
     * @return LazyLoadingValueHolderFactory
     */
    public function getProxyFactory()
    {
        return $this->proxyFactory;
    }

    /**
     * @param LazyLoadingValueHolderFactory $proxyFactory
     */
    public function setProxyFactory(LazyLoadingValueHolderFactory $proxyFactory)
    {
        $this->proxyFactory = $proxyFactory;
    }

    /**
     * @return DefinitionManager
     */
    public function getDefinitionManager()
    {
        return $this->definitionManager;
    }

    /**
     * @param ClassDefinition $classDefinition
     * @throws DependencyException
     * @throws \Exception
     * @return object The instance
     */
    private function getNewInstance(ClassDefinition $classDefinition)
    {
        $classname = $classDefinition->getClassName();

        if (isset($this->classesBeingInstantiated[$classname])) {
            throw new DependencyException("Circular dependency detected while trying to instantiate class '$classname'");
        }
        $this->classesBeingInstantiated[$classname] = true;

        try {
            $instance = $this->injector->createInstance($classDefinition);
        } catch (Exception $exception) {
            unset($this->classesBeingInstantiated[$classname]);
            throw $exception;
        }

        unset($this->classesBeingInstantiated[$classname]);
        return $instance;
    }

    /**
     * Returns a proxy instance
     *
     * @param ClassDefinition $definition
     * @return object Proxy instance
     */
    private function getProxy(ClassDefinition $definition)
    {
        $proxy = $this->proxyFactory->createProxy(
            $definition->getClassName(),
            function (& $wrappedObject, $proxy, $method, $parameters, & $initializer) use ($definition) {
                $wrappedObject = $this->getNewInstance($definition);
                $initializer = null; // turning off further lazy initialization
                return true;
            }
        );

        return $proxy;
    }

    /**
     * @return LazyLoadingValueHolderFactory
     */
    private function createDefaultProxyFactory()
    {
        // Proxy factory
        $config = new ProxyManagerConfiguration();
        // By default, auto-generate proxies and don't write them to file
        $config->setAutoGenerateProxies(true);
        $config->setGeneratorStrategy(new EvaluatingGeneratorStrategy());

        return new LazyLoadingValueHolderFactory($config);
    }
}
