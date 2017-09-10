<?php declare(strict_types = 1);

namespace PHPStan\Type;

use PHPStan\TrinaryLogic;

class UnionType implements CompoundType, StaticResolvableType
{

	/** @var \PHPStan\Type\Type[] */
	private $types;

	/**
	 * @param Type[] $types
	 */
	public function __construct(array $types)
	{
		$throwException = function () use ($types) {
			throw new \PHPStan\ShouldNotHappenException(sprintf(
				'Cannot create %s with: %s',
				self::class,
				implode(', ', array_map(function (Type $type): string {
					return $type->describe();
				}, $types))
			));
		};
		if (count($types) < 2) {
			$throwException();
		}
		foreach ($types as $type) {
			if ($type instanceof UnionType) {
				$throwException();
			}
		}
		$this->types = UnionTypeHelper::sortTypes($types);
	}

	/**
	 * @return \PHPStan\Type\Type[]
	 */
	public function getTypes(): array
	{
		return $this->types;
	}

	/**
	 * @return string|null
	 */
	public function getClass()
	{
		return UnionTypeHelper::getClass($this->getTypes());
	}

	/**
	 * @return string[]
	 */
	public function getReferencedClasses(): array
	{
		return UnionTypeHelper::getReferencedClasses($this->getTypes());
	}

	public function accepts(Type $type): bool
	{
		if ($type instanceof CompoundType) {
			return CompoundTypeHelper::accepts($type, $this);
		}

		if (TypeCombinator::shouldSkipUnionTypeAccepts($this)) {
			return true;
		}

		foreach ($this->getTypes() as $otherType) {
			if ($otherType->accepts($type)) {
				return true;
			}
		}

		return false;
	}

	public function isSupersetOf(Type $otherType): TrinaryLogic
	{
		if ($otherType instanceof self || $otherType instanceof IterableIterableType) {
			return $otherType->isSubsetOf($this);
		}

		$results = [];
		foreach ($this->getTypes() as $innerType) {
			$results[] = $innerType->isSupersetOf($otherType);
		}

		return TrinaryLogic::createNo()->or(...$results);
	}

	public function isSubsetOf(Type $otherType): TrinaryLogic
	{
		$results = [];
		foreach ($this->getTypes() as $innerType) {
			$results[] = $otherType->isSupersetOf($innerType);
		}

		return TrinaryLogic::extremeIdentity(...$results);
	}

	public function describe(): string
	{
		$typeNames = [];

		foreach ($this->types as $type) {
			if ($type instanceof IntersectionType) {
				$typeNames[] = sprintf('(%s)', $type->describe());
			} else {
				$typeNames[] = $type->describe();
			}
		}

		return implode('|', $typeNames);
	}

	public function canAccessProperties(): bool
	{
		foreach ($this->types as $type) {
			if ($type instanceof NullType) {
				continue;
			}
			if (!$type->canAccessProperties()) {
				return false;
			}
		}

		return true;
	}

	public function canCallMethods(): bool
	{
		foreach ($this->types as $type) {
			if ($type instanceof NullType) {
				continue;
			}
			if (!$type->canCallMethods()) {
				return false;
			}
		}

		return true;
	}

	public function isDocumentableNatively(): bool
	{
		return false;
	}

	public function resolveStatic(string $className): Type
	{
		return new self(UnionTypeHelper::resolveStatic($className, $this->getTypes()));
	}

	public function changeBaseClass(string $className): StaticResolvableType
	{
		return new self(UnionTypeHelper::changeBaseClass($className, $this->getTypes()));
	}

	public function isIterable(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->isIterable();
		});
	}

	public function getIterableKeyType(): Type
	{
		return $this->unionTypes(function (Type $type): Type {
			return $type->getIterableKeyType();
		});
	}

	public function getIterableValueType(): Type
	{
		return $this->unionTypes(function (Type $type): Type {
			return $type->getIterableValueType();
		});
	}

	public function isCallable(): TrinaryLogic
	{
		return $this->unionResults(function (Type $type): TrinaryLogic {
			return $type->isCallable();
		});
	}

	public static function __set_state(array $properties): Type
	{
		return new self($properties['types']);
	}

	private function unionResults(callable $getResult): TrinaryLogic
	{
		return TrinaryLogic::extremeIdentity(...array_map($getResult, $this->types));
	}

	private function unionTypes(callable $getType): Type
	{
		return TypeCombinator::union(...array_map($getType, $this->types));
	}

}
