<?php

namespace App\Form;

use App\Dto\NewScientist;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ScientistType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'attr' => ['placeholder' => 'Name', 'maxlength' => 80],
            ])
            ->add('profession', TextType::class, [
                'attr' => ['placeholder' => 'Profession', 'maxlength' => 80],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => NewScientist::class]);
    }
}
