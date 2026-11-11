import { computed, ref, shallowRef } from 'vue';
import { useI18n } from 'vue-i18n';
import { errorMessage, invalidFields } from '@/api/errorMessage';
import { isApiError } from '@/api/client';
import type { Gallery, GalleriesApi } from '@/api/galleries';
import type { GalleryFormValues } from './components/GalleryFormSheet.vue';
import { copyToClipboard, useFeedback } from './feedback';
import { shortcodeFor } from './format';
import type { GalleryActionKind } from './format';

/** Where a failed action reports to: the open sheet/dialog, so the user's input stays in place. */
interface FailureUi {
  setError: (message: string, fields?: Record<string, string>) => void;
  close: () => void;
  /** Replace the target with a fresh server copy (new revision) so a retry is possible. */
  refresh: (fresh: Gallery) => void;
}

/**
 * Dialog/sheet state and the write flows of the gallery list (M2-23). No optimistic updates: every
 * action calls the API and then refetches the list. Failure policy: 404 closes the dialog and informs;
 * 409 revision conflict keeps the input, fetches the fresh revision and warns; 409 invalid state closes
 * and informs; everything else is shown inline with a translated, actionable message.
 */
export function useGalleryActions(api: GalleriesApi, reload: () => Promise<void>) {
  const { t } = useI18n();
  const feedback = useFeedback();

  // --- create / edit sheet
  const formOpen = ref(false);
  const formMode = ref<'create' | 'edit'>('create');
  const formTarget = shallowRef<Gallery | null>(null);
  const formLoading = ref(false);
  const formError = ref<string | null>(null);
  const formFieldErrors = ref<Record<string, string>>({});

  // --- duplicate sheet
  const dupOpen = ref(false);
  const dupTarget = shallowRef<Gallery | null>(null);
  const dupLoading = ref(false);
  const dupError = ref<string | null>(null);

  // --- trash / delete confirmations (one dialog state each)
  const trashOpen = ref(false);
  const trashTarget = shallowRef<Gallery | null>(null);
  const deleteOpen = ref(false);
  const deleteTarget = shallowRef<Gallery | null>(null);
  const confirmLoading = ref(false);
  const confirmError = ref<string | null>(null);

  // --- shortcode fallback
  const shortcodeOpen = ref(false);
  const shortcodeText = ref('');

  const label = (gallery: Gallery): string => gallery.title || t('galleries.card.untitled');

  async function handleFailure(
    error: unknown,
    target: Gallery | null,
    ui: FailureUi,
    fieldLabels: Record<string, string> = {},
  ): Promise<void> {
    if (isApiError(error)) {
      if (error.status === 404) {
        ui.close();
        feedback.notify(t('galleries.feedback.gone'), 'warning');
        await reload();
        return;
      }
      if (error.code === 'swg_invalid_state') {
        ui.close();
        feedback.notify(t('galleries.feedback.stale'), 'warning');
        await reload();
        return;
      }
      if (error.status === 409) {
        ui.setError(errorMessage(error, t));
        if (target !== null) {
          try {
            ui.refresh(await api.get(target.id));
          } catch (second) {
            if (isApiError(second) && second.status === 404) {
              ui.close();
              feedback.notify(t('galleries.feedback.gone'), 'warning');
            }
          }
        }
        await reload();
        return;
      }
    }
    const fields: Record<string, string> = {};
    for (const name of invalidFields(error)) {
      const fieldLabel = fieldLabels[name];
      if (fieldLabel !== undefined) fields[fieldLabel] = t('errors.fieldInvalid');
    }
    ui.setError(errorMessage(error, t), fields);
  }

  // --- create
  function openCreate(): void {
    formMode.value = 'create';
    formTarget.value = null;
    formError.value = null;
    formFieldErrors.value = {};
    formOpen.value = true;
  }

  const formUi: FailureUi = {
    setError: (message, fields = {}) => {
      formError.value = message;
      formFieldErrors.value = fields;
    },
    close: () => {
      formOpen.value = false;
    },
    refresh: (fresh) => {
      formTarget.value = fresh;
    },
  };
  const formFieldLabels = computed(() => ({
    title: t('galleries.form.title'),
    description: t('galleries.form.description'),
    status: t('galleries.form.status'),
  }));

  async function submitForm(values: GalleryFormValues): Promise<void> {
    if (formLoading.value) return;
    formError.value = null;
    formFieldErrors.value = {};
    if (formMode.value === 'create') {
      formLoading.value = true;
      try {
        const created = await api.create({
          title: values.title,
          description: values.description,
          status: 'draft',
        });
        formOpen.value = false;
        feedback.notify(t('galleries.feedback.created', { title: label(created) }));
        await reload();
      } catch (error) {
        await handleFailure(error, null, formUi, formFieldLabels.value);
      } finally {
        formLoading.value = false;
      }
      return;
    }
    const target = formTarget.value;
    if (target === null) return;
    const changes: { title?: string; description?: string; status?: GalleryFormValues['status'] } =
      {};
    if (values.title !== target.title) changes.title = values.title;
    if (values.description !== target.description) changes.description = values.description;
    // A trashed row cannot be edited here; for live rows the status is a plain field.
    if (values.status !== target.status) changes.status = values.status;
    if (Object.keys(changes).length === 0) {
      formError.value = t('errors.noChanges');
      return;
    }
    formLoading.value = true;
    try {
      const saved = await api.update(target.id, target.revision, changes);
      formOpen.value = false;
      feedback.notify(t('galleries.feedback.updated', { title: label(saved) }));
      await reload();
    } catch (error) {
      await handleFailure(error, target, formUi, formFieldLabels.value);
    } finally {
      formLoading.value = false;
    }
  }

  // --- duplicate
  const dupUi: FailureUi = {
    setError: (message) => {
      dupError.value = message;
    },
    close: () => {
      dupOpen.value = false;
    },
    refresh: (fresh) => {
      dupTarget.value = fresh;
    },
  };

  async function submitDuplicate(title: string): Promise<void> {
    const target = dupTarget.value;
    if (target === null || dupLoading.value) return;
    dupError.value = null;
    dupLoading.value = true;
    try {
      const copy = await api.duplicate(target.id, title);
      dupOpen.value = false;
      feedback.notify(t('galleries.feedback.duplicated', { title: label(copy) }));
      await reload();
    } catch (error) {
      await handleFailure(error, target, dupUi);
    } finally {
      dupLoading.value = false;
    }
  }

  // --- trash / permanent delete / restore
  const trashUi: FailureUi = {
    setError: (message) => {
      confirmError.value = message;
    },
    close: () => {
      trashOpen.value = false;
    },
    refresh: (fresh) => {
      trashTarget.value = fresh;
    },
  };
  const deleteUi: FailureUi = {
    setError: (message) => {
      confirmError.value = message;
    },
    close: () => {
      deleteOpen.value = false;
    },
    refresh: (fresh) => {
      deleteTarget.value = fresh;
    },
  };

  async function confirmTrash(): Promise<void> {
    const target = trashTarget.value;
    if (target === null || confirmLoading.value) return;
    confirmError.value = null;
    confirmLoading.value = true;
    try {
      await api.trash(target.id, target.revision);
      trashOpen.value = false;
      feedback.notify(t('galleries.feedback.trashed', { title: label(target) }));
      await reload();
    } catch (error) {
      await handleFailure(error, target, trashUi);
    } finally {
      confirmLoading.value = false;
    }
  }

  async function confirmDelete(): Promise<void> {
    const target = deleteTarget.value;
    if (target === null || confirmLoading.value) return;
    confirmError.value = null;
    confirmLoading.value = true;
    try {
      await api.deletePermanently(target.id, target.revision);
      deleteOpen.value = false;
      feedback.notify(t('galleries.feedback.deleted', { title: label(target) }));
      await reload();
    } catch (error) {
      await handleFailure(error, target, deleteUi);
    } finally {
      confirmLoading.value = false;
    }
  }

  async function restore(target: Gallery): Promise<void> {
    try {
      const restored = await api.restore(target.id, target.revision);
      feedback.notify(t('galleries.feedback.restored', { title: label(restored) }));
      await reload();
    } catch (error) {
      // No dialog to report to: a toast, then refetch so a stale revision is replaced.
      if (isApiError(error) && error.status === 404) {
        feedback.notify(t('galleries.feedback.gone'), 'warning');
      } else if (isApiError(error) && error.code === 'swg_invalid_state') {
        feedback.notify(t('galleries.feedback.stale'), 'warning');
      } else {
        feedback.notify(errorMessage(error, t), 'warning');
      }
      await reload();
    }
  }

  async function copyShortcode(target: Gallery): Promise<void> {
    const text = shortcodeFor(target.id);
    if (await copyToClipboard(text)) {
      feedback.notify(t('galleries.feedback.copied', { shortcode: text }));
    } else {
      shortcodeText.value = text;
      shortcodeOpen.value = true;
    }
  }

  /** Entry point for the row menus. */
  function run(kind: GalleryActionKind, gallery: Gallery): void {
    switch (kind) {
      case 'edit':
        formMode.value = 'edit';
        formTarget.value = gallery;
        formError.value = null;
        formFieldErrors.value = {};
        formOpen.value = true;
        break;
      case 'duplicate':
        dupTarget.value = gallery;
        dupError.value = null;
        dupOpen.value = true;
        break;
      case 'trash':
        trashTarget.value = gallery;
        confirmError.value = null;
        trashOpen.value = true;
        break;
      case 'delete':
        deleteTarget.value = gallery;
        confirmError.value = null;
        deleteOpen.value = true;
        break;
      case 'restore':
        void restore(gallery);
        break;
      case 'copy':
        void copyShortcode(gallery);
        break;
    }
  }

  return {
    run,
    openCreate,
    form: {
      open: formOpen,
      mode: formMode,
      target: formTarget,
      loading: formLoading,
      error: formError,
      fieldErrors: formFieldErrors,
      submit: submitForm,
    },
    duplicate: {
      open: dupOpen,
      target: dupTarget,
      loading: dupLoading,
      error: dupError,
      submit: submitDuplicate,
    },
    trash: { open: trashOpen, target: trashTarget, confirm: confirmTrash },
    remove: { open: deleteOpen, target: deleteTarget, confirm: confirmDelete },
    confirmLoading,
    confirmError,
    shortcode: { open: shortcodeOpen, text: shortcodeText },
  };
}
