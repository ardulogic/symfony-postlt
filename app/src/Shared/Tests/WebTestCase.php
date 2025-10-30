<?php
declare(strict_types=1);

namespace App\Shared\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as Base;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

abstract class WebTestCase extends Base
{
    protected EntityManagerInterface $em;
    protected Container $c;

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->catchExceptions(false);

        $this->c = static::getContainer();
        $this->em = $this->c->get(EntityManagerInterface::class);

        $tools = static::getContainer()->get(DatabaseToolCollection::class);
        $dbTool = $tools->get('default'); // pass your Doctrine manager name

        $dbTool->loadFixtures($this->getRequiredFixtures());
    }

    abstract protected function getRequiredFixtures(): array;

    /**
     * If we expect errors we need to set client to catchExceptions:true
     * Otherwise our test will be treated as failed
     * @return void
     */
    protected function expectError(): void
    {
        $this->client->catchExceptions(true);
    }

    /**     * If we expect success, we want to see stack traces of failed code parts
     * @return void
     */
    protected function expectSuccess(): void
    {
        $this->client->catchExceptions(false);
    }

    protected function url(string $route, array $params = [], int $refType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        /** @var RouterInterface $router */
        $router = static::getContainer()->get(RouterInterface::class);

        return $router->generate($route, $params, $refType);
    }

}
