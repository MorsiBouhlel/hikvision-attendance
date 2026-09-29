<?php

namespace App\Form;

use App\Entity\HrSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class HrSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('remoteWorkDaysPerMonth', IntegerType::class, [
                'label' => 'hr_settings.remote_work_days',
                'attr' => ['min' => 0],
                'constraints' => [new Assert\NotNull(), new Assert\PositiveOrZero()],
            ])
            ->add('permissionHoursPerMonth', NumberType::class, [
                'label' => 'hr_settings.permission_hours',
                'html5' => true,
                'scale' => 2,
                'attr' => ['step' => '0.5', 'min' => 0],
                'constraints' => [new Assert\NotNull(), new Assert\PositiveOrZero()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => HrSettings::class]);
    }
}
