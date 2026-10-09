<?php

return [

    /*
    | Base instruction prepended to every template. {age} and {gender_word}
    | are replaced per guest. Edit the text here without changing code.
    */
    'base' => 'Using the person in the provided photo as the identity reference, create a photorealistic portrait of what this exact person would have looked like as a {age}-year-old {gender_word}. Preserve the recognizable facial structure, eye shape, skin tone, and hair color of the person. Age-appropriate, wholesome, family-friendly, fully clothed. No text or watermarks.',

    'templates' => [
        'playground' => [
            'prompt' => 'Setting: a sunny playground, natural daylight, candid smiling expression, casual colorful clothes.',
        ],
        'studio' => [
            'prompt' => 'Setting: classic school-portrait studio, soft even lighting, plain pastel backdrop, neat clothes, gentle smile.',
        ],
        'outdoor' => [
            'prompt' => 'Setting: a green park or garden at golden hour, shallow depth of field, relaxed happy expression, light layered clothing.',
        ],
    ],

];
