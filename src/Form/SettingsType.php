<?php

namespace App\Form;

use App\Entity\Organization;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('notificationEmail', EmailType::class, [
                'label' => 'Adresse prévenue des nouveaux messages',
                'required' => false,
            ])
            ->add('botEnabled', CheckboxType::class, [
                'label' => 'Répondre automatiquement sur WhatsApp',
                'required' => false,
            ])
            ->add('botMessage', TextareaType::class, [
                'label' => 'Message automatique',
                'help' => 'Première réponse reçue par le client sur WhatsApp, puis de nouveau après 12 heures de silence. Pensez à l\'inviter à poser sa question.',
                'attr' => ['rows' => 5],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Organization::class]);
    }
}
