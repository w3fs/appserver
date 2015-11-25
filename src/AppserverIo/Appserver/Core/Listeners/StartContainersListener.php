<?php

/**
 * AppserverIo\Appserver\Core\Listeners\StartContainersListener
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * PHP version 5
 *
 * @author    Tim Wagner <tw@appserver.io>
 * @copyright 2015 TechDivision GmbH <info@appserver.io>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/appserver-io/appserver
 * @link      http://www.appserver.io
 */

namespace AppserverIo\Appserver\Core\Listeners;

use League\Event\EventInterface;
use AppserverIo\Appserver\Core\Interfaces\ApplicationServerInterface;

/**
 * Listener that initializes and binds the containers found in the system configuration.
 *
 * @author    Tim Wagner <tw@appserver.io>
 * @copyright 2015 TechDivision GmbH <info@appserver.io>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/appserver-io/appserver
 * @link      http://www.appserver.io
 */
class StartContainersListener extends AbstractSystemListener
{

    /**
     * Handle an event.
     *
     * @param \League\Event\EventInterface $event The triggering event
     *
     * @return void
     * @see \League\Event\ListenerInterface::handle()
     */
    public function handle(EventInterface $event)
    {

        try {
            // load the application server instance
            /** @var \AppserverIo\Appserver\Core\Interfaces\ApplicationServerInterface $applicationServer */
            $applicationServer = $this->getApplicationServer();

            // write a log message that the event has been invoked
            $applicationServer->getSystemLogger()->info($event->getName());

            // initialize the service to load the container configurations
            /** @var \AppserverIo\Appserver\Core\Api\DeploymentService $deploymentService */
            $deploymentService = $applicationServer->newService('AppserverIo\Appserver\Core\Api\DeploymentService');
            $applicationServer->setSystemConfiguration($deploymentService->loadContainerInstances());

            // load the naming directory
            /** @var \AppserverIo\Appserver\Naming\NamingDirectory $namingDirectory */
            $namingDirectory = $applicationServer->getNamingDirectory();

            // initialize the environment variables
            $namingDirectory->bind('php:env/tmpDirectory', $deploymentService->getTmpDir());
            $namingDirectory->bind('php:env/baseDirectory', $deploymentService->getBaseDirectory());
            $namingDirectory->bind('php:env/umask', $applicationServer->getSystemConfiguration()->getUmask());
            $namingDirectory->bind('php:env/user', $applicationServer->getSystemConfiguration()->getUser());
            $namingDirectory->bind('php:env/group', $applicationServer->getSystemConfiguration()->getGroup());

            // and initialize a container thread for each container
            /** @var \AppserverIo\Appserver\Core\Api\Node\ContainerNodeInterface $containerNode */
            foreach ($applicationServer->getSystemConfiguration()->getContainers() as $containerNode) {
                /** @var \AppserverIo\Appserver\Core\Interfaces\ContainerFactoryInterface $containerFactory */
                $containerFactory = $containerNode->getFactory();

                // use the factory if available
                /** @var \AppserverIo\Appserver\Core\Interfaces\ContainerInterface $container */
                $container = $containerFactory::factory($applicationServer, $containerNode);
                $container->start();

                // wait until all servers has been bound to their ports and addresses
                while ($container->hasServersStarted() === false) {
                    // sleep to avoid cpu load
                    usleep(10000);
                }

                // register the container as service
                $applicationServer->bindService(ApplicationServerInterface::NETWORK, $container);
            }

        } catch (\Exception $e) {
            $applicationServer->getSystemLogger()->error($e->__toString());
        }
    }
}
