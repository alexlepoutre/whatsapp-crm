<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class AccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'constraints' => [new NotBlank(message: 'Indiquez l\'adresse e-mail.'), new Email(message: 'Cette adresse e-mail n\'est pas valide.'), new Length(max: 180)],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Mot de passe',
                'help' => '12 caractères minimum. Transmettez-le à la personne par un canal sûr.',
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [new NotBlank(message: 'Choisissez un mot de passe.'), new Length(min: 12, max: 4096, minMessage: '12 caractères minimum.')],
            ])
        ;
    }
}
