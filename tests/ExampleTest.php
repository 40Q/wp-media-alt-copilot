<?php

test('media alt suggester loads', function () {
    expect(class_exists(\FortyQ\MediaAltSuggester\MediaAltSuggesterServiceProvider::class))->toBeTrue();
});
