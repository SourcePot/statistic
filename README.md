# FhG invoices
This is a Datapool processor, i.e. the class implements the processor interface. The processor provides a user interface and data processing for entries containing parsed invoices.

<img src="./assets/2024-05-31_schematic.png"/>

# Example
Each entry containing an invoice is checked aginst rules. This is done when the user clicks the "Process invoices" button. The content admin or admin had configured a random threshold defining how many of the invoices matching the rules (typically 100%) and not matching the rules (typically <100%) should be held for a manual check. All other invoices are forwarded to the canvas element selected by "Target success",e.g. "Warten" in the image.

<img src="./assets/2024-05-31check.png"/>

For the manual check the user gets a list of the held back invoices and is asked to "approve" or "dec.line" each invoice. Declined invoices will be forwarded to canvas element selected by "Target failure" the approved invoices will be forwarded to canvas element selected by "Target success".

<img src="./assets/2024-05-31_user_action.png"/>

The next time the "Process invoices" button is clicked or the canvas element is triggered by the CanvbasProcessing job processed entries will be forwarded.

<img src="./assets/2024-05-31processed.png"/>