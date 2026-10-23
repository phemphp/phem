<?php

namespace App\DataFixtures;

use App\Model\Scientist;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

// The three scientists of the Gallery example. Load them with `bin/console doctrine:fixtures:load`.
final class ScientistFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $manager->persist(new Scientist('Maria Skłodowska-Curie', 'physicist and chemist'));
        $manager->persist(new Scientist('Katsuko Saruhashi', 'geochemist'));
        $manager->persist(new Scientist('Lise Meitner', 'physicist'));

        $manager->flush();
    }
}
