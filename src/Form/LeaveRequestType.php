<?php

namespace App\Form;

use App\Entity\LeaveRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class LeaveRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('startDate', DateType::class, [
                'label' => 'form.start_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('endDate', DateType::class, [
                'label' => 'form.end_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'form.type',
                'choices' => [
                    'leave_type.conge' => 'conge',
                    'leave_type.maladie' => 'maladie',
                    'leave_type.autre' => 'autre',
                ],
            ])
            ->add('reason', TextType::class, [
                'label' => 'form.reason',
                'required' => false,
                'constraints' => [new Assert\Length(max: 255)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LeaveRequest::class]);
    }
}
