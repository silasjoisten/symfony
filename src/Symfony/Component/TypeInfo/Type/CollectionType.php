<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TypeInfo\Type;

use Symfony\Component\TypeInfo\Exception\InvalidArgumentException;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\TypeIdentifier;

/**
 * Represents a key/value collection type.
 *
 * @author Mathias Arlaud <mathias.arlaud@gmail.com>
 * @author Baptiste Leduc <baptiste.leduc@gmail.com>
 *
 * @template T of BuiltinType<TypeIdentifier::ARRAY>|BuiltinType<TypeIdentifier::ITERABLE>|ObjectType|GenericType
 *
 * @implements WrappingTypeInterface<T>
 */
final class CollectionType extends Type implements WrappingTypeInterface
{
    /**
     * @param T $type
     */
    public function __construct(
        private readonly BuiltinType|ObjectType|GenericType $type,
        private readonly bool $isList = false,
    ) {
        if ($type instanceof BuiltinType && TypeIdentifier::ARRAY !== $type->getTypeIdentifier() && TypeIdentifier::ITERABLE !== $type->getTypeIdentifier()) {
            throw new InvalidArgumentException(\sprintf('Cannot create "%s" with "%s" type.', self::class, $type));
        }

        if ($this->isList()) {
            if (!$type->isIdentifiedBy(TypeIdentifier::ARRAY)) {
                trigger_deprecation('symfony/type-info', '7.3', 'Creating a "%s" that is a list and not an array is deprecated and will throw a "%s" in 8.0.', self::class, InvalidArgumentException::class);
                // throw new InvalidArgumentException(\sprintf('Cannot create a "%s" as list when type is not "array".', self::class));
            }

            $keyType = $this->getCollectionKeyType();

            if (!$keyType instanceof BuiltinType || TypeIdentifier::INT !== $keyType->getTypeIdentifier()) {
                throw new InvalidArgumentException(\sprintf('"%s" is not a valid list key type.', (string) $keyType));
            }
        }
    }

    public function getWrappedType(): Type
    {
        return $this->type;
    }

    public function isList(): bool
    {
        return $this->isList;
    }

    public function getCollectionKeyType(): Type
    {
        $defaultCollectionKeyType = self::union(self::int(), self::string());

        if ($this->type instanceof GenericType) {
            return match (\count($this->type->getVariableTypes())) {
                2 => $this->type->getVariableTypes()[0],
                1 => self::int(),
                default => $defaultCollectionKeyType,
            };
        }

        return $defaultCollectionKeyType;
    }

    public function getCollectionValueType(): Type
    {
        $defaultCollectionValueType = self::mixed();

        if ($this->type instanceof GenericType) {
            return match (\count($this->type->getVariableTypes())) {
                2 => $this->type->getVariableTypes()[1],
                1 => $this->type->getVariableTypes()[0],
                default => $defaultCollectionValueType,
            };
        }

        return $defaultCollectionValueType;
    }

    public function wrappedTypeIsSatisfiedBy(callable $specification): bool
    {
        return $this->getWrappedType()->isSatisfiedBy($specification);
    }

    public function accepts(mixed $value): bool
    {
        if (!parent::accepts($value)) {
            return false;
        }

        if ($this->isList() && (!\is_array($value) || !array_is_list($value))) {
            return false;
        }

        $keyType = $this->getCollectionKeyType();
        $valueType = $this->getCollectionValueType();

        if (is_iterable($value)) {
            foreach ($value as $k => $v) {
                // key or value do not match
                if (!$keyType->accepts($k) || !$valueType->accepts($v)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function __toString(): string
    {
        return (string) $this->type;
    }
}
