<?php

namespace Hal\Metric\Class_\Custom;

use Hal\Metric\Metrics;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter;

// Убеждаемся, что глобальные функции phpmetrics доступны
if (!function_exists('getNameOfNode')) {
    require_once __DIR__ . '/../../../../functions.php';
}

class DetailedMethodVisitor extends NodeVisitorAbstract
{
    /** @var Metrics */
    private $metrics;
    private $classMetricStack = [];
    private $currentMethodNode = null;
    private $currentMethodMetric = null;
    private $methodCalls = [];
    private $methodPropertyReads = [];
    private $methodPropertyWrites = [];
    private $methodInstantiations = [];
    private $methodClosures = [];
    private $methodDynamicCalls = [];
    private $methodEvents = [];
    private $methodQueueCalls = [];

    public function __construct(Metrics $metrics)
    {
        $this->metrics = $metrics;
    }

    public function beforeTraverse(array $nodes)
    {
        $this->classMetricStack = [];
        $this->currentMethodNode = null;
        $this->currentMethodMetric = null;
        $this->methodCalls = [];
        $this->methodPropertyReads = [];
        $this->methodPropertyWrites = [];
        $this->methodInstantiations = [];
        $this->methodClosures = [];
        $this->methodDynamicCalls = [];
        $this->methodEvents = [];
        $this->methodQueueCalls = [];
        return null;
    }

    public function enterNode(Node $node)
    {
        // Track class context
        if (\Hal\Component\Ast\NodeTyper::isOrganizedStructure($node)) {
            $className = \getNameOfNode($node);
            if ($className) {
                array_push($this->classMetricStack, $className);
            }
        }

        // When entering a method, start collecting data
        if (!empty($this->classMetricStack) && $node instanceof Stmt\ClassMethod) {
            $this->currentMethodNode = $node;
            $this->methodCalls = [];
            $this->methodPropertyReads = [];
            $this->methodPropertyWrites = [];
            $this->methodInstantiations = [];
            $this->methodClosures = [];
            $this->methodDynamicCalls = [];
            $this->methodEvents = [];
            $this->methodQueueCalls = [];
        }

        // Collect method calls, property reads/writes while inside a method
        if ($this->currentMethodNode) {
            // Track instantiations (new keyword)
            if ($node instanceof Node\Expr\New_) {
                $className = \getNameOfNode($node->class);
                if ($className && $className !== 'anonymous@' . spl_object_hash($node->class)) {
                    $instantiation = [
                        'class' => $className,
                        'args' => count($node->args),
                        'isChained' => $this->isChainedInstantiation($node)
                    ];

                    // Track constructor argument types for dependency analysis
                    $dependencies = [];
                    foreach ($node->args as $arg) {
                        $argType = $this->inferArgumentType($arg->value);
                        if ($argType) {
                            $dependencies[] = $argType;
                        }
                    }
                    if (!empty($dependencies)) {
                        $instantiation['constructorDeps'] = $dependencies;
                    }

                    $this->methodInstantiations[] = $instantiation;
                }
            }

            // Track closures and arrow functions
            if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
                $closure = [
                    'type' => $node instanceof Node\Expr\ArrowFunction ? 'arrow' : 'closure',
                    'params' => count($node->params),
                    'uses' => []
                ];

                // Capture use variables (closure only, arrow functions capture automatically)
                if ($node instanceof Node\Expr\Closure && isset($node->uses)) {
                    foreach ($node->uses as $use) {
                        if ($use->var instanceof Node\Expr\Variable && is_string($use->var->name)) {
                            $closure['uses'][] = $use->var->name;
                        }
                    }
                }

                $this->methodClosures[] = $closure;
            }

            // Track method calls
            if ($node instanceof Node\Expr\MethodCall) {
                $caller = $this->resolveCallerName($node->var);
                $methodName = $this->resolveMethodName($node->name);

                if ($methodName !== null) {
                    $this->methodCalls[] = ['caller' => $caller, 'method' => $methodName];
                } else {
                    // Dynamic method call
                    $this->methodDynamicCalls[] = [
                        'caller' => $caller,
                        'type' => 'dynamic_method',
                        'nameType' => get_class($node->name)
                    ];
                }
            }

            if ($node instanceof Node\Expr\StaticCall) {
                $caller = \getNameOfNode($node->class);
                $methodName = $this->resolveMethodName($node->name);

                if ($methodName !== null) {
                    $this->methodCalls[] = ['caller' => $caller, 'method' => $methodName];

                    // Detect event dispatching
                    if ($this->isEventDispatch($caller, $methodName)) {
                        $eventData = ['dispatcher' => $caller, 'method' => $methodName, 'line' => $node->getLine()];
                        if (!empty($node->args[0]) && $node->args[0]->value instanceof Node\Expr\New_) {
                            $eventClass = \getNameOfNode($node->args[0]->value->class);
                            if ($eventClass) $eventData['event'] = $eventClass;
                        }
                        $this->methodEvents[] = $eventData;
                    }

                    // Detect queue calls
                    if ($this->isQueueCall($caller, $methodName)) {
                        $queueData = ['class' => $caller, 'method' => $methodName, 'args' => count($node->args), 'line' => $node->getLine()];
                        if (!empty($node->args[0]) && $node->args[0]->value instanceof Node\Expr\New_) {
                            $jobClass = \getNameOfNode($node->args[0]->value->class);
                            if ($jobClass) $queueData['job'] = $jobClass;
                        }
                        $this->methodQueueCalls[] = $queueData;
                    }
                } else {
                    // Dynamic static call
                    $this->methodDynamicCalls[] = [
                        'caller' => $caller,
                        'type' => 'dynamic_static',
                        'nameType' => get_class($node->name)
                    ];
                }
            }

            // Track call_user_func and similar
            if ($node instanceof Node\Expr\FuncCall) {
                $funcName = \getNameOfNode($node->name);
                if (in_array($funcName, ['call_user_func', 'call_user_func_array', 'array_map', 'array_filter'])) {
                    $this->methodDynamicCalls[] = [
                        'type' => 'func_call',
                        'function' => $funcName,
                        'args' => count($node->args)
                    ];
                }
            }

            if ($node instanceof Node\Expr\PropertyFetch && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
                $propName = $this->resolvePropertyName($node->name);
                if ($propName !== null && !in_array($propName, $this->methodPropertyReads)) {
                    $this->methodPropertyReads[] = $propName;
                }
            }

            if ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\PropertyFetch) {
                if ($node->var->var instanceof Node\Expr\Variable && $node->var->var->name === 'this') {
                    $propName = $this->resolvePropertyName($node->var->name);
                    if ($propName !== null && !in_array($propName, $this->methodPropertyWrites)) {
                        $this->methodPropertyWrites[] = $propName;
                    }
                }
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        // When leaving a method, attach collected data to the metric
        if ($this->currentMethodNode && $node instanceof Stmt\ClassMethod && $node === $this->currentMethodNode) {
            $currentClassName = !empty($this->classMetricStack) ? end($this->classMetricStack) : null;

            if ($currentClassName) {
                $classMetric = $this->metrics->get($currentClassName);

                if ($classMetric) {
                    $methodName = (string) $node->name;
                    $methods = $classMetric->get('methods');

                    if ($methods) {
                        foreach ($methods as $metric) {
                            if ($metric->getName() === $methodName) {
                                $prettyPrinter = new PrettyPrinter\Standard();
                                $methodBody = $prettyPrinter->prettyPrint([$node]);

                                $metric->set('calls', $this->methodCalls);
                                $metric->set('propertyReads', $this->methodPropertyReads);
                                $metric->set('propertyWrites', $this->methodPropertyWrites);
                                $metric->set('instantiates', $this->methodInstantiations);
                                $metric->set('closures', $this->methodClosures);
                                $metric->set('dynamicCalls', $this->methodDynamicCalls);
                                $metric->set('events', $this->methodEvents);
                                $metric->set('queueCalls', $this->methodQueueCalls);
                                $metric->set('body', $methodBody);
                                break;
                            }
                        }
                    }
                }
            }

            $this->currentMethodNode = null;
            $this->methodCalls = [];
            $this->methodPropertyReads = [];
            $this->methodPropertyWrites = [];
            $this->methodInstantiations = [];
            $this->methodClosures = [];
            $this->methodDynamicCalls = [];
            $this->methodEvents = [];
            $this->methodQueueCalls = [];
        }

        // Pop class context when leaving a class
        if (\Hal\Component\Ast\NodeTyper::isOrganizedStructure($node)) {
            array_pop($this->classMetricStack);
        }

        return null;
    }

    private function resolveCallerName(Node $node): ?string
    {
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return '$' . $node->name;
        }
        return \getNameOfNode($node);
    }

    private function resolveMethodName($nameNode): ?string
    {
        // Если это просто строка (Identifier), возвращаем её
        if (is_string($nameNode)) {
            return $nameNode;
        }

        // Если это Node\Identifier
        if ($nameNode instanceof Node\Identifier) {
            return (string) $nameNode;
        }

        // Если это динамический вызов (переменная), пропускаем
        if ($nameNode instanceof Node\Expr) {
            return null;
        }

        // Пытаемся привести к строке
        return (string) $nameNode;
    }

    private function resolvePropertyName($nameNode): ?string
    {
        // Если это просто строка (Identifier), возвращаем её
        if (is_string($nameNode)) {
            return $nameNode;
        }

        // Если это Node\Identifier или Node\VarLikeIdentifier
        if ($nameNode instanceof Node\Identifier || $nameNode instanceof Node\VarLikeIdentifier) {
            return (string) $nameNode;
        }

        // Если это динамическое свойство (переменная), пропускаем
        if ($nameNode instanceof Node\Expr) {
            return null;
        }

        // Пытаемся привести к строке
        return (string) $nameNode;
    }

    /**
     * Check if instantiation is part of a fluent chain (e.g., (new Foo())->bar())
     */
    private function isChainedInstantiation(Node\Expr\New_ $node): bool
    {
        // We need to check parent context, but php-parser doesn't provide parent info directly
        // For now, return false - we'll detect fluent chains in PatternAnalysisVisitor
        return false;
    }

    /**
     * Infer the type of an argument passed to constructor
     */
    private function inferArgumentType(Node $argNode): ?string
    {
        // Variable
        if ($argNode instanceof Node\Expr\Variable) {
            return null; // Can't infer type from variable name alone
        }

        // New expression
        if ($argNode instanceof Node\Expr\New_) {
            return \getNameOfNode($argNode->class);
        }

        // Class constant (e.g., SomeClass::class)
        if ($argNode instanceof Node\Expr\ClassConstFetch) {
            if ($argNode->name instanceof Node\Identifier && (string)$argNode->name === 'class') {
                return \getNameOfNode($argNode->class);
            }
        }

        // Static method call that might be a factory
        if ($argNode instanceof Node\Expr\StaticCall) {
            return \getNameOfNode($argNode->class);
        }

        // Method call (dependency from another object)
        if ($argNode instanceof Node\Expr\MethodCall) {
            // Can't reliably infer without type information
            return null;
        }

        // Property fetch
        if ($argNode instanceof Node\Expr\PropertyFetch) {
            // Can't infer type without context
            return null;
        }

        return null;
    }

    private function isEventDispatch(?string $className, ?string $methodName): bool
    {
        if (!$className || !$methodName) return false;
        $patterns = ['Event' => ['dispatch','fire'], 'Dispatcher' => ['dispatch','fire']];
        foreach ($patterns as $class => $methods) {
            if (stripos($className, $class) !== false && in_array($methodName, $methods)) return true;
        }
        return false;
    }

    private function isQueueCall(?string $className, ?string $methodName): bool
    {
        if (!$className || !$methodName) return false;
        if ($methodName === 'queue') return true;
        if (stripos($className, 'Queue') !== false && in_array($methodName, ['push','later','dispatch'])) return true;
        if (stripos($className, 'QueueServ') !== false) return true;
        return false;
    }
}
