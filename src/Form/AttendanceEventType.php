<?php

namespace App\Form;

use App\Entity\AttendanceEvent;
use App\Entity\Device;
use Doctrine\Common\Collections\Collection;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaire de correction manuelle d'un pointage (admin uniquement) — crée
 * ou édite un AttendanceEvent avec eventType = 'manual'. 'reason' n'est pas
 * mappé sur l'entité, il alimente l'AttendanceCorrection créée à côté par le
 * contrôleur (voir AttendanceCorrectionController).
 */
class AttendanceEventType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('device', EntityType::class, [
                'label' => 'common.device',
                'class' => Device::class,
                'choices' => $options['device_choices'],
                'choice_label' => 'name',
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('occurredAt', DateTimeType::class, [
                'label' => 'punches.time',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('attendanceStatus', ChoiceType::class, [
                'label' => 'punches.type',
                'choices' => [
                    'punches.check_in' => 'checkIn',
                    'punches.check_out' => 'checkOut',
                ],
                'placeholder' => 'punches.unspecified',
                'required' => false,
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'form.reason',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Assert\Length(max: 500)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AttendanceEvent::class,
        ]);
        $resolver->setRequired('device_choices');
        $resolver->setAllowedTypes('device_choices', [Collection::class, 'array']);
    }
}
