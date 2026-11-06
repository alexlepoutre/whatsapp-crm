<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class AccountEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'help' => 'C\'est l\'identifiant de connexion.',
                'constraints' => [new NotBlank(message: 'Indiquez l\'adresse e-mail.'), new Email(message: 'Cette adresse e-mail n\'est pas valide.'), new Length(max: 180)],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Nouveau mot de passe',
                'help' => 'Laissez vide pour conserver le mot de passe actuel. 12 caractères minimum.',
                'required' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [new Length(min: 12, max: 4096, minMessage: '12 caractères minimum.')],
            ])
        ;
    }
}
