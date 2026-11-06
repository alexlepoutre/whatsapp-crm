<?php

namespace App\Form;

use App\Entity\Organization;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

class OrganizationEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom affiché'])
            ->add('notificationEmail', EmailType::class, [
                'label' => 'Adresse prévenue des nouveaux messages',
                'required' => false,
            ])
            ->add('primaryColor', ColorType::class, [
                'label' => 'Couleur primaire',
                'help' => 'Boutons et liens.',
            ])
            ->add('secondaryColor', ColorType::class, [
                'label' => 'Couleur secondaire',
                'help' => 'Menu de l\'administration et bandeau des pages publiques.',
            ])
            ->add('logo', FileType::class, [
                'label' => 'Logo',
                'help' => 'PNG, JPEG ou WebP, 1 Mo maximum.',
                'mapped' => false,
                'required' => false,
                // Pas de SVG : ce format peut embarquer du script, servi ici sur une page publique.
                'constraints' => [new Image(
                    maxSize: '1M',
                    mimeTypes: ['image/png', 'image/jpeg', 'image/webp'],
                    mimeTypesMessage: 'Envoyez une image PNG, JPEG ou WebP.',
                    maxSizeMessage: 'Le logo dépasse 1 Mo.',
                )],
            ])
            ->add('removeLogo', CheckboxType::class, [
                'label' => 'Retirer le logo actuel',
                'mapped' => false,
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Organization::class]);
    }
}
