<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Votre nom',
                'attr' => ['autocomplete' => 'name'],
                'constraints' => [new NotBlank(message: 'Indiquez votre nom.'), new Length(max: 120)],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Votre adresse e-mail',
                'help' => 'Votre lien de suivi privé sera envoyé à cette adresse.',
                'attr' => ['autocomplete' => 'email'],
                'constraints' => [new NotBlank(message: 'Indiquez votre adresse e-mail.'), new Email(message: 'Cette adresse e-mail n\'est pas valide.'), new Length(max: 180)],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Votre téléphone (facultatif)',
                'required' => false,
                'attr' => ['autocomplete' => 'tel'],
                'constraints' => [new Length(max: 30)],
            ])
            ->add('subject', TextType::class, [
                'label' => 'Objet',
                'constraints' => [new NotBlank(message: 'Résumez votre demande en quelques mots.'), new Length(max: 150)],
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Votre message',
                'attr' => ['rows' => 6],
                'constraints' => [new NotBlank(message: 'Écrivez votre message.'), new Length(max: 4000)],
            ])
            ->add('website', TextType::class, [
                'label' => 'Ne pas remplir ce champ',
                'required' => false,
                'row_attr' => ['class' => 'trap'],
                'attr' => ['autocomplete' => 'off', 'tabindex' => '-1'],
            ])
        ;
    }
}
