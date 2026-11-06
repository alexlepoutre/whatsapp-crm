<?php

namespace App\Form;

use App\Entity\Organization;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

class OrganizationMailType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('smtpUsername', EmailType::class, [
                'label' => 'Adresse d\'envoi',
                'help' => 'L\'adresse complète de la boîte OVH. Les courriels partent de cette adresse. Videz ce champ pour désactiver l\'envoi propre à cette organisation.',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'placeholder' => 'contact@exemple.fr'],
            ])
            ->add('smtpPassword', PasswordType::class, [
                'label' => 'Mot de passe de la boîte',
                'help' => 'Laissez vide pour conserver le mot de passe enregistré.',
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [new Length(max: 255)],
            ])
            ->add('smtpHost', TextType::class, [
                'label' => 'Serveur SMTP',
                'help' => 'ssl0.ovh.net pour une boîte MX Plan ou E-mail Pro mutualisée.',
            ])
            ->add('smtpPort', ChoiceType::class, [
                'label' => 'Port',
                'choices' => ['465 (SSL)' => 465, '587 (STARTTLS)' => 587],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Organization::class]);
    }
}
