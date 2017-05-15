<?php

use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;

class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = array(
            new Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
            new Symfony\Bundle\SecurityBundle\SecurityBundle(),
            new Symfony\Bundle\TwigBundle\TwigBundle(),
            new Symfony\Bundle\MonologBundle\MonologBundle(),
            new Symfony\Bundle\SwiftmailerBundle\SwiftmailerBundle(),
            new Symfony\Bundle\AsseticBundle\AsseticBundle(),
            new Doctrine\Bundle\DoctrineBundle\DoctrineBundle(),
            new Sensio\Bundle\FrameworkExtraBundle\SensioFrameworkExtraBundle(),
            new JMS\AopBundle\JMSAopBundle(),
            new JMS\DiExtraBundle\JMSDiExtraBundle($this),
            new JMS\SecurityExtraBundle\JMSSecurityExtraBundle(),
            new ODR\AdminBundle\ODRAdminBundle(),
//            new ODR\LoginBundle\ODRLoginBundle(),
            new FOS\UserBundle\FOSUserBundle(),
            new FOS\OAuthServerBundle\FOSOAuthServerBundle(),
            new ODR\OpenRepository\UserBundle\ODROpenRepositoryUserBundle(),
            new ODR\OpenRepository\OAuthBundle\ODROpenRepositoryOAuthBundle(),
            new ODR\OpenRepository\SearchBundle\ODROpenRepositorySearchBundle(),
            new ODR\OpenRepository\ApiBundle\ODROpenRepositoryApiBundle(),          // why is this a thing?
            new ODR\OpenRepository\GraphBundle\ODROpenRepositoryGraphBundle(),
            new drymek\PheanstalkBundle\drymekPheanstalkBundle(),
            new dterranova\Bundle\CryptoBundle\dterranovaCryptoBundle(),
            new Knp\Bundle\MarkdownBundle\KnpMarkdownBundle(),
            new Snc\RedisBundle\SncRedisBundle(),
            new HWI\Bundle\OAuthBundle\HWIOAuthBundle(),
        );

        if (in_array($this->getEnvironment(), array('dev', 'test'))) {
            $bundles[] = new Symfony\Bundle\WebProfilerBundle\WebProfilerBundle();
            $bundles[] = new Sensio\Bundle\DistributionBundle\SensioDistributionBundle();
            $bundles[] = new Sensio\Bundle\GeneratorBundle\SensioGeneratorBundle();
        }

        return $bundles;
    }

    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        $loader->load(__DIR__.'/config/config_'.$this->getEnvironment().'.yml');
    }
}
