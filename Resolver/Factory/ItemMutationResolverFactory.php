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

use ApiPlatform\Core\GraphQl\Resolver\MutationResolverInterface;
use ApiPlatform\Core\GraphQl\Resolver\Stage\DenyAccessStageInterface;
use ApiPlatform\Core\GraphQl\Resolver\Stage\DeserializeStageInterface;
use ApiPlatform\Core\GraphQl\Resolver\Stage\ReadStageInterface;
use ApiPlatform\Core\GraphQl\Resolver\Stage\SerializeStageInterface;
use ApiPlatform\Core\GraphQl\Resolver\Stage\ValidateStageInterface;
use ApiPlatform\Core\GraphQl\Resolver\Stage\WriteStageInterface;
use ApiPlatform\Core\Metadata\Resource\Factory\ResourceMetadataFactoryInterface;
use ApiPlatform\Core\Util\ClassInfoTrait;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;
use Psr\Container\ContainerInterface;

/**
 * Creates a function resolving a GraphQL mutation of an item.
 *
 * @experimental
 *
 * @author Alan Poulain <contact@alanpoulain.eu>
 */
final class ItemMutationResolverFactory implements ResolverFactoryInterface
{
    use ClassInfoTrait;

    private $readStage;
    private $denyAccessStage;
    private $serializeStage;
    private $deserializeStage;
    private $writeStage;
    private $validateStage;
    private $mutationResolverLocator;
    private $resourceMetadataFactory;

    public function __construct(ReadStageInterface $readStage, DenyAccessStageInterface $denyAccessStage, SerializeStageInterface $serializeStage, DeserializeStageInterface $deserializeStage, WriteStageInterface $writeStage, ValidateStageInterface $validateStage, ContainerInterface $mutationResolverLocator, ResourceMetadataFactoryInterface $resourceMetadataFactory)
    {
        $this->readStage = $readStage;
        $this->denyAccessStage = $denyAccessStage;
        $this->serializeStage = $serializeStage;
        $this->deserializeStage = $deserializeStage;
        $this->writeStage = $writeStage;
        $this->validateStage = $validateStage;
        $this->mutationResolverLocator = $mutationResolverLocator;
        $this->resourceMetadataFactory = $resourceMetadataFactory;
    }

    public function __invoke(?string $resourceClass = null, ?string $rootClass = null, ?string $operationName = null): callable
    {
        return function (?array $source, array $args, $context, ResolveInfo $info) use ($resourceClass, $rootClass, $operationName) {
            if (null === $resourceClass || null === $operationName) {
                return null;
            }

            $resolverContext = ['source' => $source, 'args' => $args, 'info' => $info, 'is_collection' => false, 'is_mutation' => true];

            $item = $this->readStage->apply($resourceClass, $rootClass, $operationName, $resolverContext);
            if (null !== $item && !\is_object($item)) {
                throw new \LogicException('Item from read stage should be a nullable object.');
            }

            $previousItem = \is_object($item) ? clone $item : $item;

            if ('delete' === $operationName) {
                $this->denyAccessStage->apply($resourceClass, $operationName, $resolverContext + [
                    'extra_variables' => [
                        'object' => $item,
                        'previous_object' => $previousItem,
                    ],
                ]);

                $item = $this->writeStage->apply($item, $resourceClass, $operationName, $resolverContext);

                return $this->serializeStage->apply($item, $resourceClass, $operationName, $resolverContext);
            }

            $item = $this->deserializeStage->apply($item, $resourceClass, $operationName, $resolverContext);

            $resourceMetadata = $this->resourceMetadataFactory->create($resourceClass);

            $mutationResolverId = $resourceMetadata->getGraphqlAttribute($operationName, 'mutation');
            if (null !== $mutationResolverId) {
                /** @var MutationResolverInterface $mutationResolver */
                $mutationResolver = $this->mutationResolverLocator->get($mutationResolverId);
                $item = $mutationResolver($item, $resolverContext);
                if (null !== $item && $resourceClass !== $itemClass = $this->getObjectClass($item)) {
                    throw Error::createLocatedError(sprintf('Custom mutation resolver "%s" has to return an item of class %s but returned an item of class %s.', $mutationResolverId, $resourceMetadata->getShortName(), (new \ReflectionClass($itemClass))->getShortName()), $info->fieldNodes, $info->path);
                }
            }

            $this->denyAccessStage->apply($resourceClass, $operationName, $resolverContext + [
                'extra_variables' => [
                    'object' => $item,
                    'previous_object' => $previousItem,
                ],
            ]);

            if (null !== $item) {
                $this->validateStage->apply($item, $resourceClass, $operationName, $resolverContext);

                $persistResult = $this->writeStage->apply($item, $resourceClass, $operationName, $resolverContext);
            }

            return $this->serializeStage->apply($persistResult ?? $item, $resourceClass, $operationName, $resolverContext);
        };
    }
}
