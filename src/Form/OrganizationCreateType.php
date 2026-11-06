<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class OrganizationCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom affiché',
                'attr' => ['placeholder' => 'Dufermont'],
                'constraints' => [new NotBlank(message: 'Indiquez le nom.'), new Length(max: 120)],
            ])
            ->add('slug', TextType::class, [
                'label' => 'Adresse de la page',
                'help' => 'Minuscules, chiffres et tirets. Elle sert aussi de code WhatsApp et ne pourra plus changer.',
                'attr' => ['placeholder' => 'dufermont'],
                'constraints' => [
                    new NotBlank(message: 'Indiquez l\'adresse.'),
                    new Length(max: 50),
                    new Regex('/^[a-z0-9][a-z0-9-]*$/', message: 'Minuscules, chiffres et tirets uniquement.'),
                ],
            ])
            ->add('notificationEmail', EmailType::class, [
                'label' => 'Adresse prévenue des nouveaux messages',
                'required' => false,
                'constraints' => [new Email(message: 'Cette adresse e-mail n\'est pas valide.')],
            ])
        ;
    }
}
