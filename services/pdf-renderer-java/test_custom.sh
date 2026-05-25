#!/bin/bash
echo "Testing custom mode with function stamps..."
curl -X POST http://localhost:8080/api/pdf/process \
  -F "pdf=@src/test/resources/samples/test2.pdf" \
  -F "perforation_image=@src/test/resources/samples/perf.png" \
  -F "signature_appearance_image=@src/test/resources/samples/sig.png" \
  -F "function_stamp_0=@src/test/resources/samples/stamp1.png" \
  -F "function_stamp_1=@src/test/resources/samples/stamp2.png" \
  -F "mode=custom" \
  -F "function_stamp_count=2" \
  -F "hash_algo=SHA256" \
  -F "tsa_enabled=false" \
  -o output_custom_fixed.pdf
echo "Done. Check output_custom_fixed.pdf"
