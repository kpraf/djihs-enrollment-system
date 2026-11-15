# Watching the registrar encode: a test kit

A script can show where a form is slow, but only a real person shows where it is confusing. This kit is for sitting beside the registrar while they encode about 10 learners, and writing down what happens.

## Before the session

- **When:** during real encoding, such as the enrollment period, on the real system. It's real work, so no data is copied for the test.
- **Who:** the registrar or whoever encodes most often. Ask an adviser too, if they encode.
- **Bring:** this sheet printed, and a watch or phone timer.
- **Don't** photograph the screen or the forms. They contain learners' personal data.
- Ask them to work as usual and say out loud what they're thinking ("Where's the…?", "Why is it asking…?"). Tell them you're testing the system, not them.
- Don't help unless they've been stuck for a full minute. Write down what they were stuck on, then help.

## What to ask them to do

Encode the next 10 learners from the forms on their desk. If possible, include:
- at least one **returning learner** whose LRN is already in the system;
- one **transferee**;
- one learner with **only a guardian**, no parents;
- one learner who is **Indigenous, a 4Ps beneficiary or has a disability**.

## Observation sheet

| # | Start | End | Where they paused or hesitated | Mistakes (and whether the form caught them) | What they said | Workarounds (paper notes, calculator, asking someone) |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | | | | | | |
| 2 | | | | | | |
| 3 | | | | | | |
| 4 | | | | | | |
| 5 | | | | | | |
| 6 | | | | | | |
| 7 | | | | | | |
| 8 | | | | | | |
| 9 | | | | | | |
| 10 | | | | | | |

## Questions afterwards

1. Which part of the form took the longest? Why?
2. Was anything on the form not on the paper enrollment form, or the other way round?
3. Did any message confuse you?
4. What do you still write down on paper or in Excel instead of in the system?
5. If you could change one thing, what would it be?

## What a simulated session already found

A script played a registrar encoding 10 invented learners at an average typist's speed. It typed realistic data, made common mistakes and counted every click and keystroke. Fixed after the simulation:

- **Birth date:** clicking into the date box and typing left it blank in the test browser. It is now a plain box that takes `04182010`, `04/18/2010`, `2010-04-18` or `April 18, 2010`, and shows the date it understood with the age.
- **Grade level and strand:** they reset after every save. They are now kept for the next learner, with a note saying so.
- **After a save,** the cursor wasn't anywhere. It is now in the LRN box, ready for the next learner.
- **After a mistake,** the problem scrolled into view but the cursor stayed put. It is now in the field to fix.
- **Phone numbers** typed as `+63 917 555 0142` were refused. They are now accepted and stored as `09175550142`.
- **LRN length:** a short LRN was only caught after pressing Submit. The box now counts the digits while typing ("11 of 12 digits").
- **Screen readers** heard "Last Name" three times in the parents section. They now hear "Father's Last Name", "Mother's Last Name" and "Guardian's Last Name".

What worked already:
- A learner already enrolled this year is flagged as soon as the LRN is typed.
- A returning learner's saved details fill the form with one click on **Use their saved details**. That cut the work for that learner from about 90 seconds to about 30.
- Lowercase names are saved in capitals, as in the school's workbook.

Estimated time for 10 learners, using the Keystroke-Level Model for an average typist: about 12 minutes, or 70–120 seconds per new learner and about 30 seconds per returning learner. Real people pause to read forms and talk to parents, so expect more. If the real session takes much longer, the observation sheet will show where.

The simulation script and its numbers are a guide, not proof. Questions only a real session can answer:
- Do the labels match the words on the paper form?
- Is anything on the form never filled in?
- Where does the registrar look away to the paper and lose their place?
