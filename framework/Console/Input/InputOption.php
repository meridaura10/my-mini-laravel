<?php

namespace Framework\Kernel\Console\Input;

use Framework\Kernel\Console\Exceptions\InvalidArgumentException;
use Framework\Kernel\Console\Exceptions\LogicException;

class InputOption
{
    public const VALUE_NONE = 1;

    public const VALUE_REQUIRED = 2;

    public const VALUE_OPTIONAL = 4;

    public const VALUE_IS_ARRAY = 8;

    public const VALUE_NEGATABLE = 16;

    private string $name;

    private string|array|null $shortcut;

    private int $mode;

    private string|int|bool|array|null|float $default;

    private array|\Closure $suggestedValues;

    private string $description;

    public function __construct(
        string $name,
        string|array|null $shortcut = null,
        ?int $mode = null,
        string $description = '',
        string|bool|int|float|array|null $default = null,
        array|\Closure $suggestedValues = []
    ) {
        if (str_starts_with($name, '--')) {
            $name = substr($name, 2);
        }

        if (empty($name)) {
            throw new InvalidArgumentException('An option name cannot be empty.');
        }

        if (empty($shortcut)) {
            $shortcut = null;
        }

        if ($shortcut) {
            if (\is_array($shortcut)) {
                $shortcut = implode('|', $shortcut);
            }
            $shortcuts = preg_split('{(\|)-?}', ltrim($shortcut, '-'));
            $shortcuts = array_filter($shortcuts);
            $shortcut = implode('|', $shortcuts);

            if (empty($shortcut)) {
                throw new InvalidArgumentException('An option shortcut cannot be empty.');
            }
        }

        if (! $mode) {
            $mode = self::VALUE_NONE;
        } elseif ($mode >= (self::VALUE_NEGATABLE << 1) || $mode < 1) {
            throw new InvalidArgumentException(sprintf('Option mode "%s" is not valid.', $mode));
        }

        $this->name = $name;
        $this->shortcut = $shortcut;
        $this->mode = $mode;
        $this->description = $description;
        $this->suggestedValues = $suggestedValues;

        if ($suggestedValues && ! $this->acceptValue()) {
            throw new LogicException('Cannot set suggested values if the option does not accept a value.');
        }
        if ($this->isArray() && ! $this->acceptValue()) {
            throw new InvalidArgumentException('Impossible to have an option mode VALUE_IS_ARRAY if the option does not accept a value.');
        }
        if ($this->isNegatable() && $this->acceptValue()) {
            throw new InvalidArgumentException('Impossible to have an option mode VALUE_NEGATABLE if the option also accepts a value.');
        }

        $this->setDefault($default);
    }

    public function setDefault(string|bool|int|float|array|null $default = null): void
    {
        if (self::VALUE_NONE === (self::VALUE_NONE & $this->mode) && $default !== null) {
            throw new LogicException('Cannot set a default value when using InputOption::VALUE_NONE mode.');
        }

        if ($this->isArray()) {
            if ($default === null) {
                $default = [];
            } elseif (! \is_array($default)) {
                throw new LogicException('A default value for an array option must be an array.');
            }
        }

        $this->default = $this->acceptValue() || $this->isNegatable() ? $default : false;
    }

    public function equals(self $option): bool
    {
        return $option->getName() === $this->getName()
            && $option->getShortcut() === $this->getShortcut()
            && $option->getDefault() === $this->getDefault()
            && $option->isNegatable() === $this->isNegatable()
            && $option->isArray() === $this->isArray()
            && $option->isValueRequired() === $this->isValueRequired()
            && $option->isValueOptional() === $this->isValueOptional();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function acceptValue(): bool
    {
        return $this->isValueRequired() || $this->isValueOptional();
    }

    public function isValueRequired(): bool
    {
        return self::VALUE_REQUIRED === (self::VALUE_REQUIRED & $this->mode);
    }

    public function isValueOptional(): bool
    {
        return self::VALUE_OPTIONAL === (self::VALUE_OPTIONAL & $this->mode);
    }

    public function isArray(): bool
    {
        return self::VALUE_IS_ARRAY === (self::VALUE_IS_ARRAY & $this->mode);
    }

    public function isNegatable(): bool
    {
        return self::VALUE_NEGATABLE === (self::VALUE_NEGATABLE & $this->mode);
    }

    public function setSuggestedValues(array|\Closure $suggestedValues): void
    {
        $this->suggestedValues = $suggestedValues;
    }

    public function getShortcut(): array|string|null
    {
        return $this->shortcut;
    }

    public function getSuggestedValues(): array|\Closure
    {
        return $this->suggestedValues;
    }

    public function getMode(): int
    {
        return $this->mode;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getDefault(): float|int|bool|array|string|null
    {
        return $this->default;
    }
}
