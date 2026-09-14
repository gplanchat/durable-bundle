<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Says what to configure when the resume lock has no factory.
 *
 * The DBAL backend has no server to serialise the tasks of one execution: `SingleResumeLockMiddleware`
 * does it, and without it two workers replay the same journal at the same time. Its factory is taken
 * from the application's container.
 *
 * Without `framework.lock` that service does not exist and compilation already fails — on a
 * "non-existent service" that names `lock.factory` and leaves the operator searching. What the
 * operator needs to know is not which service is missing, but which configuration section would have
 * provided it, and why it is not optional here.
 *
 * Checked in a pass rather than in the extension: when extensions load, the one that registers
 * `lock.factory` has not necessarily run yet, and an existence check there would answer false for a
 * correctly configured application.
 */
final class RequireLockFactoryPass implements CompilerPassInterface
{
    private const LOCK_SERVICE = 'durable.dbal.single_resume_lock';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::LOCK_SERVICE)) {
            return;
        }

        // The application may have redefined the service without arguments; fall back to the
        // conventional name rather than failing on the argument read.
        $arguments = $container->getDefinition(self::LOCK_SERVICE)->getArguments();
        $factory = (string) ($arguments[0] ?? 'lock.factory');

        if ($container->has($factory)) {
            return;
        }

        throw new \LogicException(\sprintf(
            'durable: the DBAL backend serialises the resumes of one execution with a lock, '
            . 'and the service "%s" that provides it does not exist. Enable the Lock component — '
            . '`framework.lock: true` in config/packages/framework.yaml, or a `framework.lock.resources` entry '
            . 'pointing at a store shared between your processes — or name your own factory in '
            . '`durable.dbal.lock_factory`. Without a lock, two workers replay the same journal at the same time.',
            $factory,
        ));
    }
}
