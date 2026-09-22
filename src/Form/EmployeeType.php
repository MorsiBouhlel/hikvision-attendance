<?php

namespace App\Form;

use App\Entity\Department;
use App\Entity\Employee;
use App\Entity\WorkSchedule;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class EmployeeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'form.first_name',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'form.name',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
            ])
            ->add('department', EntityType::class, [
                'class' => Department::class,
                'choice_label' => 'name',
                'label' => 'form.department',
                'required' => false,
                'placeholder' => 'form.no_department',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'form.active',
                'required' => false,
            ])
            ->add('workSchedule', EntityType::class, [
                'class' => WorkSchedule::class,
                'choice_label' => 'name',
                'label' => 'form.work_schedule',
                'required' => false,
                'placeholder' => 'form.no_schedule',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Employee::class]);
    }
}
