<?php

namespace App\Form;

use App\Entity\Device;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class DeviceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
            ])
            ->add('site', TextType::class, [
                'label' => 'Site',
                'required' => false,
                'constraints' => [new Assert\Length(max: 100)],
            ])
            ->add('ipAddress', TextType::class, [
                'label' => 'Adresse IP',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 45)],
            ])
            ->add('port', IntegerType::class, [
                'label' => 'Port',
                'constraints' => [new Assert\NotBlank(), new Assert\Range(min: 1, max: 65535)],
            ])
            ->add('adminUser', TextType::class, [
                'label' => 'Utilisateur admin',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 50)],
            ])
            ->add('adminPassword', PasswordType::class, [
                'label' => $options['is_edit'] ? 'Mot de passe admin (laisser vide pour ne pas changer)' : 'Mot de passe admin',
                'mapped' => false,
                'required' => ! $options['is_edit'],
                'constraints' => $options['is_edit'] ? [] : [new Assert\NotBlank()],
            ])
            ->add('serialNumber', TextType::class, [
                'label' => 'Numéro de série',
                'required' => false,
                'constraints' => [new Assert\Length(max: 100)],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Actif',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Device::class,
            'is_edit' => false,
        ]);
    }
}
