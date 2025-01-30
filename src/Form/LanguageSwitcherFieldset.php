<?php declare(strict_types=1);

namespace Internationalisation\Form;

use Laminas\Form\Element\Radio;
use Laminas\Form\Fieldset;

class LanguageSwitcherFieldset extends Fieldset
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][display_locale]',
                'type' => Radio::class,
                'options' => [
                    'label' => 'Type of display for language', // @translate
                    'value_options' => [
                        [
                            'value' => 'code',
                            'label'  => 'Language code', // @translate
                            'selected' => true,
                        ],
                        [
                            'value' => 'flag',
                            'label'  => 'Language flag', // @translate
                        ],
                    ],
                ],
                'attributes' => [
                    'id' => 'display_locale',
                ],
            ]);
    }
}
