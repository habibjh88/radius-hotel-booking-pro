/**
 * The free runtime pieces the access screens use (`window.rtbp.lib`).
 */
import { api, toast, toastError, access } from '@rtbp/lib';

export { api, toast, toastError };

/**
 * Refetch the viewer's own access map, when the free plugin has it.
 *
 * @return {void}
 */
export const refreshAccess = () => access?.refreshAccess?.();
