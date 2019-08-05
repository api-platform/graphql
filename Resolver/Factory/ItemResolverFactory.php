<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\GraphQl\Resolver\Factory;

use ApiPlatform\Core\GraphQl\Resolver\QueryItemResolverInterface;
use ApiPlatform\Core\GraphQl\Resolver\Stage\DenyAccessStageInterface;
use ApiPlatform\Core\GraphQl\Resolver\Stage\ReadStageInterface;
use ApiPlatform\Core\GraphQl\Resolver\Stage\SerializeStageInterface;
use ApiPlatform\Core\Metadata\Resource\Factory\ResourceMetadataFactoryInterface;
use ApiPlatform\Core\Util\ClassInfoTrait;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;
use Psr\Container\ContainerInterface;

/**
 * Creates a function retrieving an item to resolve a GraphQL query.
 *
 * @experimental
 *
 * @author Alan Poulain <contact@alanpoulain.eu>
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class ItemResolverFactory implements ResolverFactoryInterface
{
    use ClassInfoTrait;

    private $readStage;
    private $denyAccessStage;
    private $serializeStage;
    private $queryResolverLocator;
    private $resourceMetadataFactory;

    public function __construct(ReadStageInterface $readStage, DenyAccessStageInterface $denyAccessStage, SerializeStageInterface $serializeStage, ContainerInterface $queryResolverLocator, ResourceMetadataFactoryInterface $resourceMetadataFactory)
    {
        $this->readStage = $readStage;
        $this->denyAccessStage = $denyAccessStage;
        $this->serializeStage = $serializeStage;
        $this->queryResolverLocator = $queryResolverLocator;
        $this->resourceMetadataFactory = $resourceMetadataFactory;
    }

    public function __invoke(?string $resourceClass = null, ?string $rootClass = null, ?string $operationName = null): callable
    {
        return function (?array $source, array $args, $context, ResolveInfo $info) use ($resourceClass, $rootClass, $operationName) {
            // Data already fetched and normalized (field or nested resource)
            if (isset($source[$info->fieldName])) {
                return $source[$info->fieldName];
            }

            $operationName = $operationName ?? 'query';
            $resolverContext = ['source' => $source, 'args' => $args, 'info' => $info, 'is_collection' => false, 'is_mutation' => false];

            $item = $this->readStage->apply($resourceClass, $rootClass, $operationName, $resolverContext);
            if (null !== $item && !\is_object($item)) {
                throw new \LogicException('Item from read stage should be a nullable object.');
            }

            $resourceClass = $this->getResourceClass($item, $resourceClass, $info);
            $resourceMetadata = $this->resourceMetadataFactory->create($resourceClass);

            $queryResolverId = $resourceMetadata->getGraphqlAttribute($operationName, 'item_query');
            if (null !== $queryResolverId) {
                /** @var QueryItemResolverInterface $queryResolver */
                $queryResolver = $this->queryResolverLocator->get($queryResolverId);
                $item = $queryResolver($item, ['source' => $source, 'args' => $args, 'info' => $info]);
                $resourceClass = $this->getResourceClass($item, $resourceClass, $info, sprintf('Custom query resolver "%s"', $queryResolverId).' has to return an item of class %s but returned an item of class %s.');
            }

            $this->denyAccessStage->apply($resourceClass, $operationName, $resolverContext + [
                'extra_variables' => [
                    'object' => $item,
                    'previous_object' => \is_object($item) ? clone $item : $item,
                ],
            ]);

            return $this->serializeStage->apply($item, $resourceClass, $operationName, $resolverContext);
        };
    }

    /**
     * @param object|null $item
     *
     * @throws Error
     */
    private function getResourceClass($item, ?string $resourceClass, ResolveInfo $info, string $errorMessage = 'Resolver only handles items of class %s but retrieved item is of class %s.'): string
    {
        if (null === $item) {
            if (null === $resourceClass) {
                throw Error::createLocatedError('Resource class cannot be determined.', $info->fieldNodes, $info->path);
            }

            return $resourceClass;
        }

        $itemClass = $this->getObjectClass($item);

        if (null === $resourceClass) {
            return $itemClass;
        }

        if ($resourceClass !== $itemClass) {
            throw Error::createLocatedError(sprintf($errorMessage, (new \ReflectionClass($resourceClass))->getShortName(), (new \ReflectionClass($itemClass))->getShortName()), $info->fieldNodes, $info->path);
        }

        return $resourceClass;
    }
}
