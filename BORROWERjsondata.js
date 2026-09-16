BORROWER:
https://akme.preprod.kugelblitz.xyz:8443/fineract-provider/api/v1/loans/newApplicationForm

{
    "entity": "client",
    "entityType": "client",
    "identifiers": [],
    "clientData": {
        "legalFormId": 1, // 1=> individual, 2 => non individual
        "officeId": 102, // Need to verify with akme
        "salutation": 446, // /clients/0/familymembers/template [GET]
        "firstName": "Mahendra",
        "lastName": "Singh Rathore",
        "fatherName": "Gopal Singh Rathore",
        "genderId": 2722,// /clients/0/familymembers/template
        "dateOfBirth": "04 September 1999",
        "age": 26,
        "mobileNo": "9876543210",
        "alternateContactNumber": "9829012345",
        "emailAddress": "mahendra.rathore@example.in",
        "isMobileVerified": false,
        "isEarner": false,
        "numberOfDependants": 4, // E
        "active": true,
        "accountNo":"", // keep this empty 
        "submittedOnDate": "10 September 2026",
        "activationDate": "10 September 2026",
        "dateFormat": "dd MMMM yyyy",
        "locale": "en",
        "clientNameJson": {}, // keep it blank
        "mobileKycJson": {},// keep it blank
        "faceMatchJson": null,
        "familyMembers": [],
        "address": [
            {
                "addressTypeId": 1, // 
                "addressSubTypeId": 0, // 
                "addressLine1": "Plot 14, Shanti Nagar",
                "landmark": "Opposite Triveni Circle",
                "townVillage": "Mansarovar",
                "city": "Jaipur",
                "district": "Jaipur",
                "state": "Rajasthan",
                "country": "India",
                "postalCode": "302020",
                "isCommunicationAddress": true,
                "locale": "en",
                "dateFormat": "dd MMMM yyyy"
            },
            {
                "addressTypeId": 1,
                "addressSubTypeId": 1,
                "addressLine1": "Plot 14, Shanti Nagar",
                "landmark": "Opposite Triveni Circle",
                "townVillage": "Mansarovar",
                "city": "Jaipur",
                "district": "Jaipur",
                "state": "Rajasthan",
                "country": "India",
                "postalCode": "302020",
                "isCommunicationAddress": false,
                "locale": "en",
                "dateFormat": "dd MMMM yyyy"
            }
        ]
    },
    // product related ids and their definition can be retrieve from loans/template
    "loanData": {
        "productId": 33, // 33	EV THREE WHEELER L3
        "officeId": 102,
        "loanType": "individual",
        "loanPurposeId": 771, // is it same as credit bureau purpose code?
        "principal": 500000,
        "numberOfRepayments": 24,
        "repaymentEvery": 1, // once in month i guess
        "repaymentFrequencyType": 2, // 2 => month
        "loanTermFrequency": 24, // 
        "loanTermFrequencyType": 2, // 2 => month
        "interestRatePerPeriod": 24, // decimal Configuration Dependent. // is it the interest rate
        "interestType": 0, // 0 => declining, 1 => flat
        "interestCalculationPeriodType": 1, // 0 => daily, 1 => monthly
        "allowPartialPeriodInterestCalcualtion": true, // explain
        "amortizationType": 1, // 0 => equal principal, 1 => equal installments 
        "isEqualAmortization": false, // explain
        "transactionProcessingStrategyId": 1, // need ids and their definition 
        "submittedOnDate": "22 August 2026", // 
        "dateFormat": "dd MMMM yyyy",
        "locale": "en",
        "isLoanApplication": true,
        "internalSalesId": 158, // 
        "isSelfSourced": false,
        "interestUpfront": false,

        // not applicable for us
        "isBrokenPeriodInterestUpfront": false,
        "brokenPeriodInterestComputationType": 0,
        
        "principalThresholdForLastInstallmentofLoan": 50, // for our case it will be zero [0]
        "AddPartialPeriodInterest": "N/A", 
        "useSop": true,
        "charges": [],
        "disbursementData": [],
        "checklistTemplate": []
    },
    "kycData": [
        {
            "entity": "client",
            "locale": "en",
            "verificationTypeId": 3, // 2 for our case as we don't do any kind of manual verification
            "documentTypeId": 1, // need the ids and definition 
            "kycTypeId": 0, // Enum — 0 = Primary, 1 = Secondary, 2 = Other // which to use
            "validationStatusId": 0, // Enum — 0 = Initiated, 1 = Validated, 2 =Failed // 1 => in our case
            "legalFormTypeId": 1, // 1 => individual
            "isFrontSide": true, // 
            "isBackSide": true,
            "isOcr": false,
            "isDocumentDrivingLicence": false,
            "isDocumentUdyam": false,
            "documentKey": "889900990822" // 
        },
        {
            "entity": "client",
            "locale": "en",
            "verificationTypeId": 3,
            "documentTypeId": 2,
            "kycTypeId": 1,
            "validationStatusId": 0,
            "legalFormTypeId": 1,
            "isFrontSide": true,
            "isBackSide": false,
            "isOcr": false,
            "isDocumentDrivingLicence": false,
            "isDocumentUdyam": false,
            "documentKey": "AAAAS4455A"
        }
    ]
}