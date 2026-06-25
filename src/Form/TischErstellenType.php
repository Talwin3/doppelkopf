<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\TischSteuerungsModus;
use App\Enum\ZugangsModusTyp;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class TischErstellenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'       => 'Tischname',
                'attr'        => ['placeholder' => 'z. B. Feierabendrunde'],
                'constraints' => [
                    new NotBlank(['message' => 'Bitte einen Tischnamen eingeben.']),
                    new Length([
                        'min'        => 3,
                        'max'        => 50,
                        'minMessage' => 'Mindestens 3 Zeichen.',
                        'maxMessage' => 'Maximal 50 Zeichen.',
                    ]),
                ],
            ])
            ->add('zugangsmodus', EnumType::class, [
                'class'        => ZugangsModusTyp::class,
                'label'        => 'Zugang',
                'choice_label' => fn(ZugangsModusTyp $m) => match ($m) {
                    ZugangsModusTyp::OFFEN  => 'Offen (jeder kann beitreten)',
                    ZugangsModusTyp::PRIVAT => 'Privat (nur Gästeliste)',
                },
            ])
            ->add('steuerungsModus', EnumType::class, [
                'class'        => TischSteuerungsModus::class,
                'label'        => 'Wer darf die Einstellungen ändern?',
                'data'         => TischSteuerungsModus::default(),
                'choice_label' => fn(TischSteuerungsModus $m) => $m->label(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
