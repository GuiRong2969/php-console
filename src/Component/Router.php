<?php declare(strict_types=1);
/**
 * The file is part of inhere/console
 *
 * @author   https://github.com/inhere
 * @homepage https://github.com/inhere/php-console
 * @license  https://github.com/inhere/php-console/blob/master/LICENSE
 */

namespace Inhere\Console\Component;

use Closure;
use Inhere\Console\Command;
use Inhere\Console\Contract\CommandInterface;
use Inhere\Console\Contract\ControllerInterface;
use Inhere\Console\Contract\RouterInterface;
use Inhere\Console\Controller;
use Inhere\Console\Util\Helper;
use InvalidArgumentException;
use Toolkit\Stdlib\Obj\Traits\NameAliasTrait;
use function array_keys;
use function array_merge;
use function class_exists;
use function explode;
use function in_array;
use function is_int;
use function is_object;
use function is_string;
use function is_subclass_of;
use function method_exists;
use function preg_match;
use function strpos;
use function trim;

/**
 * Class Router - match input command find command handler
 *
 * @package Inhere\Console
 */
class Router implements RouterInterface
{
    use NameAliasTrait;

    /**
     * @var array
     */
    private array $blocked = ['help', 'version'];

    /**
     * Command delimiter char. e.g dev:serve
     *
     * @var string
     */
    private string $delimiter = ':'; // '/' ':'

    /**
     * The independent commands
     *
     * @var array
     * [
     *  'name' => [
     *      'handler' => MyCommand::class,
     *      'options' => []
     *  ]
     * ]
     */
    private array $commands = [];

    /**
     * The group commands(controller)
     *
     * @var array
     * [
     *  'name' => [
     *      'handler' => MyController::class,
     *      'options' => []
     *  ]
     * ]
     */
    private array $controllers = [];

    /**********************************************************
     * register command/group methods
     **********************************************************/

    /**
     * Register a app group command(by controller)
     *
     * @param string                     $name  The controller name
     * @param string|ControllerInterface|null $class The controller class
     * @param array{aliases: array, desc: string} $config config for group.
     *                                          array:
     *                                          - aliases     The command aliases
     *                                          - desc The description message
     *
     * @return static
     * @throws InvalidArgumentException
     */
    public function addGroup(string $name, ControllerInterface|string $class = null, array $config = []): static
    {
        /**
         * @var Controller $class name is an controller class
         */
        if (!$class && class_exists($name)) {
            $class = $name;
            $name  = $class::getName();
        }

        if (!$name || !$class) {
            Helper::throwInvalidArgument(
                'Group-command "name" and "controller" cannot be empty! name: %s, controller: %s',
                $name,
                $class
            );
        }

        $this->validateName($name);

        if (is_string($class) && !class_exists($class)) {
            Helper::throwInvalidArgument("The console controller class [$class] not exists!");
        }

        if (!is_subclass_of($class, Controller::class)) {
            Helper::throwInvalidArgument('The console controller class must is subclass of the: ' . Controller::class);
        }

        // not enable
        if (!$class::isEnabled()) {
            return $this;
        }

        $options['aliases'] = isset($options['aliases']) ? (array)$options['aliases'] : [];

        // allow define aliases in group class by Controller::aliases()
        if ($aliases = $class::aliases()) {
            $options['aliases'] = array_merge($options['aliases'], $aliases);
        }

        $this->controllers[$name] = [
            'type'    => self::TYPE_GROUP,
            'handler' => $class,
            'options' => $options,
        ];

        // has alias option
        if ($options['aliases']) {
            $this->setAlias($name, $options['aliases'], true);
        }

        return $this;
    }

    /**
     * Register a app independent console command
     *
     * @param string|class-string         $name
     * @param string|Closure|CommandInterface|null $handler
     * @param array{aliases: array, desc: string}  $config
     *
     * @return static
     */
    public function addCommand(string $name, string|Closure|CommandInterface $handler = null, array $config = []): static
    {
        if (!$handler && class_exists($name)) {
            $handler = $name;
            $name    = $name::getName();
        }

        /**
         * @var Command $name name is an command class
         */
        if (!$name || !$handler) {
            Helper::throwInvalidArgument("Command 'name' and 'handler' cannot be empty! name: $name");
        }

        $this->validateName($name);

        if (isset($this->commands[$name])) {
            Helper::throwInvalidArgument("Command '$name' have been registered!");
        }

        $options['aliases'] = isset($options['aliases']) ? (array)$options['aliases'] : [];

        if (is_string($handler)) {
            if (!class_exists($handler)) {
                Helper::throwInvalidArgument("The console command class [$handler] not exists!");
            }

            if (!is_subclass_of($handler, Command::class)) {
                Helper::throwInvalidArgument('The console command class must is subclass of the: ' . Command::class);
            }

            // not enable
            /** @var Command $handler */
            if (!$handler::isEnabled()) {
                return $this;
            }

            // allow define aliases in Command class by Command::aliases()
            if ($aliases = $handler::aliases()) {
                $options['aliases'] = array_merge($options['aliases'], $aliases);
            }
        } elseif (!is_object($handler) || !method_exists($handler, '__invoke')) {
            Helper::throwInvalidArgument(
                'The console command handler must is an subclass of %s OR a Closure OR a object have method __invoke()',
                Command::class
            );
        }

        // is an class name string
        $this->commands[$name] = [
            'type'    => self::TYPE_SINGLE,
            'handler' => $handler,
            'options' => $options,
        ];

        // has alias option
        if ($options['aliases']) {
            $this->setAlias($name, $options['aliases'], true);
        }

        return $this;
    }

    /**
     * @param array $commands
     *
     * @throws InvalidArgumentException
     */
    public function addCommands(array $commands): void
    {
        foreach ($commands as $name => $handler) {
            if (is_int($name)) {
                $this->addCommand($handler);
            } else {
                $this->addCommand($name, $handler);
            }
        }
    }

    /**
     * @param array $controllers
     *
     * @throws InvalidArgumentException
     */
    public function addControllers(array $controllers): void
    {
        foreach ($controllers as $name => $controller) {
            if (is_int($name)) {
                $this->addGroup($controller);
            } else {
                $this->addGroup($name, $controller);
            }
        }
    }

    /**********************************************************
     * match command methods
     **********************************************************/

    /**
     * ```php
     * return [
     *  type     => 1, // 1 group 2 command
     *  name     => '', // input group/command name.
     *  cmdId    => '', // format and resolved $name
     *  // for group
     *  group    => '', // group name.
     *  sub      => '', // input subcommand name. on name is group.
     *  // common info
     *  handler => handler class/object/func ...
     *  options => [
     *      aliases => [],
     *      description => '',
     *  ],
     * ]
     * ```
     * @param string $name The input command name
     *
     * @return array return route info array. If not found, will return empty array.
     */
    public function match(string $name): array
    {
        $sep  = $this->delimiter;
        $name = trim($name, $sep);
        // resolve alias
        $realName = $this->resolveAlias($name);

        // is a command name
        if ($route = $this->commands[$realName] ?? []) {
            $route['name']  = $name;
            $route['cmdId'] = $realName;
            return $route;
        }

        // maybe is a controller/group name
        $action = '';
        $iptGrp = $group = $realName;

        // like 'home:index'
        if (strpos($realName, $sep) > 0) {
            [$group, $action] = explode($sep, $realName, 2);

            $iptGrp = $group;
            $action = trim($action, ': ');
            // resolve alias
            $group = $this->resolveAlias($group);
        }

        // is group name
        if ($route = $this->controllers[$group] ?? []) {
            $route['name'] = $iptGrp;
            $route['group'] = $group;
            $route['sub']   = $action;
            $route['cmdId'] = $group . $sep . $action;
            return $route;
        }

        // not found
        return [];
    }

    /**********************************************************
     * helper methods
     **********************************************************/

    /**
     * @param string $name
     *
     * @throws InvalidArgumentException
     */
    protected function validateName(string $name): void
    {
        // '/^[a-z][\w-]*:?([a-z][\w-]+)?$/'
        $pattern = '/^[a-z][\w:-]+$/';

        if (1 !== preg_match($pattern, $name)) {
            throw new InvalidArgumentException("The command name '$name' is must match: $pattern");
        }

        // cannot be override. like: help, version
        if ($this->isBlocked($name)) {
            throw new InvalidArgumentException("The command name '$name' is not allowed. It is a built in command.");
        }
    }

    /**
     * @param callable $grpFunc
     * @param callable $cmdFunc
     */
    public function sortedEach(callable $grpFunc, callable $cmdFunc): void
    {
        // todo ...
    }

    /**********************************************************
     * getter/setter methods
     **********************************************************/

    /**
     * @return array
     */
    public function getAllNames(): array
    {
        return array_merge($this->getCommandNames(), $this->getControllerNames());
    }

    /**
     * @return array
     */
    public function getControllerNames(): array
    {
        return array_keys($this->controllers);
    }

    /**
     * @return array
     */
    public function getCommandNames(): array
    {
        return array_keys($this->commands);
    }

    /**
     * @return array
     */
    public function getControllers(): array
    {
        return $this->controllers;
    }

    /**
     * @param string $name
     *
     * @return array
     */
    public function getControllerInfo(string $name): array
    {
        return $this->controllers[$name] ?? [];
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function isController(string $name): bool
    {
        return isset($this->controllers[$name]);
    }

    /**
     * @return array
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function isCommand(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function isBlocked(string $name): bool
    {
        return in_array($name, $this->blocked, true);
    }

    /**
     * @return array
     */
    public function getBlocked(): array
    {
        return $this->blocked;
    }

    /**
     * @param array $blocked
     */
    public function setBlocked(array $blocked): void
    {
        $this->blocked = $blocked;
    }

    /**
     * @return string
     */
    public function getDelimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * @param string $delimiter
     */
    public function setDelimiter(string $delimiter): void
    {
        $this->delimiter = trim($delimiter) ?: ':';
    }
}