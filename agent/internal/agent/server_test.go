package agent

import "testing"

func TestValidateApplyParameters(t *testing.T) {
	t.Parallel()

	namespaceName, interfaceName, managementCIDR, err := validateApplyParameters(map[string]interface{}{
		"name":            "nur-lab-1",
		"interface_name":  "mgmt0",
		"management_cidr": "192.0.2.1/24",
	})
	if err != nil {
		t.Fatalf("expected parameters to validate: %v", err)
	}

	if namespaceName != "nur-lab-1" || interfaceName != "mgmt0" || managementCIDR != "192.0.2.1/24" {
		t.Fatalf("unexpected parsed values: %s %s %s", namespaceName, interfaceName, managementCIDR)
	}
}

func TestValidateApplyParametersRejectsBadCIDR(t *testing.T) {
	t.Parallel()

	if _, _, _, err := validateApplyParameters(map[string]interface{}{
		"name":            "nur-lab-1",
		"interface_name":  "mgmt0",
		"management_cidr": "not-a-cidr",
	}); err == nil {
		t.Fatal("expected invalid cidr to fail validation")
	}
}
