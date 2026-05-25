#!/bin/bash

cd src/test/resources

echo "Testing PDF signing API..."

curl -X POST http://localhost:8080/api/pdf/process \
  -F "pdf=@test-doc.pdf" \
  -F "perforation_image=@stamp.png" \
  -F "signature_appearance_image=@signature.png" \
  -F "mode=stamp_and_sign" \
  -o "../../../output_test.pdf"

if [ $? -eq 0 ]; then
    echo "PDF generated successfully at output_test.pdf"
    ls -la ../../../output_test.pdf
else
    echo "Failed to generate PDF"
fi